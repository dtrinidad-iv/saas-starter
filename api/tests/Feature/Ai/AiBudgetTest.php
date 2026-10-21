<?php

use App\Ai\AiBudget;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Prompts\ProjectSummaryPrompt;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\ProjectSummaryStatus;
use App\Jobs\GenerateProjectSummary;
use App\Models\AiUsage;
use App\Models\Project;
use App\Models\ProjectSummary;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5]);
    [$this->owner, $this->acme] = userInOrganization();
    $this->project = inOrganization($this->acme, fn () => Project::factory()->create([
        'name' => 'Website relaunch',
        'description' => 'Rebuild the marketing site.',
    ]));
});

it('sums only this UTC month\'s spend', function () {
    createAiUsage($this->acme, ['cost_usd' => '1.000000', 'created_at' => '2026-09-30 23:59:59']);
    createAiUsage($this->acme, ['cost_usd' => '2.000000', 'created_at' => '2026-10-01 00:00:00']);
    createAiUsage($this->acme, ['cost_usd' => '0.250000', 'created_at' => '2026-10-31 23:59:59']);
    createAiUsage($this->acme, ['cost_usd' => '4.000000', 'created_at' => '2026-11-01 00:00:00']);
    $budget = app(AiBudget::class);

    $this->travelTo('2026-10-15 12:00:00');
    expect($budget->spent($this->acme))->toBe(2.25)
        ->and($budget->remaining($this->acme))->toBe(2.75);

    $this->travelTo('2026-10-31 23:59:59');
    expect($budget->spent($this->acme))->toBe(2.25);

    // A new month starts from zero spend (only the row placed in November counts).
    $this->travelTo('2026-11-01 00:00:00');
    expect($budget->spent($this->acme))->toBe(4.0);
});

it('does not count other organizations\' spend', function () {
    [, $other] = userInOrganization(name: 'Other Corp');
    createAiUsage($other, ['cost_usd' => '100.000000']);
    createAiUsage($this->acme, ['cost_usd' => '1.500000']);

    expect(app(AiBudget::class)->spent($this->acme))->toBe(1.5)
        ->and(app(AiBudget::class)->exceeded($this->acme))->toBeFalse();
});

it('uses the configured default while the organization has no override', function () {
    config(['ai.monthly_budget_usd' => 3]);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.budget.limit_usd', 3)
        ->assertJsonPath('data.budget.is_default', true);
});

it('uses the organization\'s own budget when it is below the plan\'s', function () {
    subscribe($this->acme);
    config(['ai.monthly_budget_usd' => 1]);
    $this->acme->forceFill(['ai_monthly_budget_usd' => 10])->save();
    createAiUsage($this->acme, ['cost_usd' => '2.000000']);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.budget.limit_usd', 10)
        ->assertJsonPath('data.budget.plan', 'pro')
        ->assertJsonPath('data.budget.plan_limit_usd', 50)
        ->assertJsonPath('data.budget.is_default', false)
        ->assertJsonPath('data.budget.remaining_usd', 8);

    // Over the free budget, but within its own: AI still works.
    $this->postJson("/api/projects/{$this->project->id}/summary")->assertStatus(202);
    $this->ai->assertSent();
});

it('never lets the organization\'s own budget go above the plan\'s', function () {
    // E.g. set while on Pro, then the subscription ended.
    config(['ai.monthly_budget_usd' => 1]);
    $this->acme->forceFill(['ai_monthly_budget_usd' => 10])->save();
    createAiUsage($this->acme, ['cost_usd' => '2.000000']);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.budget.limit_usd', 1)
        ->assertJsonPath('data.budget.plan', 'free')
        ->assertJsonPath('data.budget.remaining_usd', 0);

    $this->postJson("/api/projects/{$this->project->id}/summary")->assertStatus(402);
    $this->ai->assertNothingSent();
});

it('refuses a budget above the plan\'s with a message that says the limit', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 5.01])
        ->assertUnprocessable()
        ->assertJsonPath('errors.monthly_budget_usd.0', 'Your plan allows up to $5.00 a month. Upgrade the plan to raise it.');

    subscribe($this->acme);
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 50])->assertOk()->assertJsonPath('data.limit_usd', 50);
});

it('turns AI off with a budget of 0', function () {
    $this->acme->forceFill(['ai_monthly_budget_usd' => 0])->save();
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/projects/{$this->project->id}/summary")
        ->assertStatus(402)
        ->assertJsonPath('code', 'ai_budget_exceeded');

    $this->ai->assertNothingSent();
});

it('refuses to regenerate with 402 once the budget is used up, without creating a summary or calling the AI', function () {
    createAiUsage($this->acme, ['cost_usd' => '5.000000']);
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/projects/{$this->project->id}/summary")
        ->assertStatus(402)
        ->assertExactJson([
            'message' => 'This organization\'s monthly AI budget is used up.',
            'code' => 'ai_budget_exceeded',
        ]);

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->count())->toBe(0)
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(1)
        ->and(auditLogOf($this->acme)->where('action', AuditAction::SummaryRequested))->toBeEmpty();
});

it('still allows a request just under the budget', function () {
    createAiUsage($this->acme, ['cost_usd' => '4.990000']);
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/projects/{$this->project->id}/summary")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'draft');
});

it('ignores last month\'s spend when deciding whether AI is allowed', function () {
    $this->travelTo('2026-10-01 00:00:00');
    createAiUsage($this->acme, ['cost_usd' => '50.000000', 'created_at' => '2026-09-30 23:59:59']);
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/projects/{$this->project->id}/summary")->assertStatus(202);
});

it('marks a queued summary failed when the budget runs out before the job calls the AI', function () {
    $summary = createSummary($this->acme, $this->project, ProjectSummaryStatus::Pending);
    createAiUsage($this->acme, ['cost_usd' => '6.000000']);

    GenerateProjectSummary::dispatch($this->acme->id, $summary->id);

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->find($summary->id))
        ->status->toBe(ProjectSummaryStatus::Failed)
        ->error->toBe('budget_exceeded')
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(1);

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/projects/{$this->project->id}/summary")
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', 'This organization\'s monthly AI budget is used up.');
});

it('refuses the call in the client itself, so nothing reaches the provider or ai_usage', function () {
    createAiUsage($this->acme, ['cost_usd' => '5.000000']);

    expect(fn () => inOrganization($this->acme, fn () => app(AiClient::class)->generate(
        app(ProjectSummaryPrompt::class)->request($this->project, null),
    )))->toThrow(AiBudgetExceeded::class);

    $this->ai->assertNothingSent();
    expect(AiUsage::withoutGlobalScopes()->count())->toBe(1);
});

it('skips the automatic summary on project creation when the budget is used up', function () {
    createAiUsage($this->acme, ['cost_usd' => '5.000000']);
    Sanctum::actingAs($this->owner);

    $projectId = $this->postJson('/api/projects', ['name' => 'New', 'description' => 'Something to summarize.'])
        ->assertCreated()
        ->json('data.id');

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->where('project_id', $projectId)->count())->toBe(0);
});

it('skips the automatic summary when the creator lacks ai.use', function () {
    $creator = createRole($this->acme, 'Creator', [Permission::ProjectsView, Permission::ProjectsCreate, Permission::ProjectsUpdateOwn]);
    Sanctum::actingAs(addMember($this->acme, $creator));

    $projectId = $this->postJson('/api/projects', ['name' => 'New', 'description' => 'Something to summarize.'])
        ->assertCreated()
        ->json('data.id');

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->where('project_id', $projectId)->count())->toBe(0);
});

it('still creates the automatic summary for a member with ai.use and budget left', function () {
    Sanctum::actingAs(addMember($this->acme, DefaultRole::Member));

    $projectId = $this->postJson('/api/projects', ['name' => 'New', 'description' => 'Something to summarize.'])
        ->assertCreated()
        ->json('data.id');

    $this->ai->assertSent();
    expect(ProjectSummary::withoutGlobalScopes()->where('project_id', $projectId)->sole()->status)
        ->toBe(ProjectSummaryStatus::Draft);
});

it('does not block one organization because another used up its budget', function () {
    [$otherOwner, $other] = userInOrganization(name: 'Other Corp');
    $otherProject = inOrganization($other, fn () => Project::factory()->create());
    createAiUsage($other, ['cost_usd' => '50.000000']);

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/projects/{$this->project->id}/summary")->assertStatus(202);

    Sanctum::actingAs($otherOwner);
    $this->postJson("/api/projects/{$otherProject->id}/summary")->assertStatus(402);
});
