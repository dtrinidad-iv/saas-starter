<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\Project;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5]);
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    createAiUsage($this->orgA, ['user_id' => $this->ownerA->id, 'feature' => 'project_summary', 'cost_usd' => '1.000000']);
    createAiUsage($this->orgB, ['user_id' => $this->ownerB->id, 'feature' => 'secret_feature', 'cost_usd' => '3.000000', 'input_tokens' => 9999]);
    $this->orgB->forceFill(['ai_monthly_budget_usd' => 50])->save();

    // Org A's owner is the attacker in every test.
    Sanctum::actingAs($this->ownerA);
});

it('never includes another organization\'s usage in the report', function () {
    $response = $this->getJson('/api/ai-usage')->assertOk();

    expect($response->json('data.totals'))->toMatchArray(['calls' => 1, 'input_tokens' => 100, 'cost_usd' => 1])
        ->and($response->json('data.by_feature.*.feature'))->toBe(['project_summary'])
        ->and($response->json('data.by_user.*.user.id'))->toBe([$this->ownerA->id])
        ->and($response->json('data.by_day.*.calls'))->toBe([1])
        ->and($response->json('data.budget'))->toMatchArray(['limit_usd' => 5, 'is_default' => true, 'spent_usd' => 1, 'remaining_usd' => 4]);
});

it('changes only its own organization\'s budget, whatever organization the request names', function () {
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 1, 'organization_id' => $this->orgB->id])
        ->assertOk()
        ->assertJsonPath('data.limit_usd', 1);

    expect($this->orgA->fresh()->ai_monthly_budget_usd)->toBe('1.00')
        ->and($this->orgB->fresh()->ai_monthly_budget_usd)->toBe('50.00')
        ->and(auditLogOf($this->orgB)->where('action', AuditAction::AiBudgetUpdated))->toBeEmpty()
        ->and(auditLogOf($this->orgA)->where('action', AuditAction::AiBudgetUpdated))->toHaveCount(1);
});

it('ignores an organization id in the report query', function () {
    $this->getJson("/api/ai-usage?organization_id={$this->orgB->id}")
        ->assertOk()
        ->assertJsonPath('data.totals.calls', 1)
        ->assertJsonPath('data.totals.cost_usd', 1);
});

// Regression: the router reuses controller instances in a long-lived process (tests,
// Octane), so a controller must not hold CurrentOrganization from its constructor, or a
// later request would act in an earlier request's organization.
it('stays in its own organization as a member of both until it switches', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);

    $this->getJson('/api/ai-usage')->assertOk()->assertJsonPath('data.totals.cost_usd', 1);
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 2])->assertOk();
    expect($this->orgB->fresh()->ai_monthly_budget_usd)->toBe('50.00');

    // After switching, it sees B's usage (and A's is gone).
    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();
    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.totals.cost_usd', 3)
        ->assertJsonPath('data.by_feature.0.feature', 'secret_feature')
        ->assertJsonPath('data.budget.limit_usd', 50)
        ->assertJsonPath('data.budget.spent_usd', 3);
});

it('cannot read another organization\'s report through a role it has there but not here', function () {
    // Member in A, owner in B: the current organization's role is what counts.
    [$user, $orgC] = userInOrganization(DefaultRole::Member, 'Org C');
    $this->orgB->members()->attach($user, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);
    Sanctum::actingAs($user);

    $this->getJson('/api/ai-usage')->assertForbidden();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 0])->assertForbidden();

    expect($this->orgB->fresh()->ai_monthly_budget_usd)->toBe('50.00')
        ->and($orgC->fresh()->ai_monthly_budget_usd)->toBeNull();
});

it('is not blocked by another organization\'s spend', function () {
    createAiUsage($this->orgB, ['cost_usd' => '100.000000']);
    $project = inOrganization($this->orgA, fn () => Project::factory()->create(['description' => 'Something']));

    $this->postJson("/api/projects/{$project->id}/summary")->assertStatus(202);
});

// Regression (same cause as above): org B's owner, right after org A's request, must
// read and write B's budget, not A's.
it('keeps back-to-back requests from two organizations in their own organization', function () {
    $this->getJson('/api/ai-usage')->assertOk()->assertJsonPath('data.budget.spent_usd', 1);
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 2])->assertOk();

    Sanctum::actingAs($this->ownerB);
    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.totals.cost_usd', 3)
        ->assertJsonPath('data.budget.spent_usd', 3)
        ->assertJsonPath('data.budget.limit_usd', 50);
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 40])->assertOk();

    expect($this->orgA->fresh()->ai_monthly_budget_usd)->toBe('2.00')
        ->and($this->orgB->fresh()->ai_monthly_budget_usd)->toBe('40.00');
});
