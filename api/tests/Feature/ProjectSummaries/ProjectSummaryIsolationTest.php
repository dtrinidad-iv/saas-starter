<?php

use App\Ai\AiRequest;
use App\Enums\DefaultRole;
use App\Enums\ProjectSummaryStatus;
use App\Jobs\GenerateProjectSummary;
use App\Models\AiUsage;
use App\Models\Project;
use App\Models\ProjectSummary;
use App\Support\CurrentOrganization;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    $this->projectA = inOrganization($this->orgA, fn () => Project::factory()->create(['name' => 'A plan', 'description' => 'A only']));
    $this->projectB = inOrganization($this->orgB, fn () => Project::factory()->create(['name' => 'B secret', 'description' => 'B confidential roadmap']));

    $this->draftB = createSummary($this->orgB, $this->projectB, ProjectSummaryStatus::Draft, ['summary' => 'B summary']);

    // Org A's owner is the attacker in every test.
    Sanctum::actingAs($this->ownerA);
});

it('cannot read another organization\'s summary', function () {
    $this->getJson("/api/projects/{$this->projectB->id}/summary")->assertNotFound();
});

it('cannot generate a summary for another organization\'s project', function () {
    $this->postJson("/api/projects/{$this->projectB->id}/summary")->assertNotFound();

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->where('project_id', $this->projectB->id)->count())->toBe(1);
});

it('cannot approve or reject another organization\'s summary', function (string $action) {
    $this->postJson("/api/projects/{$this->projectB->id}/summaries/{$this->draftB->id}/{$action}")->assertNotFound();

    expect(ProjectSummary::withoutGlobalScopes()->find($this->draftB->id))
        ->status->toBe(ProjectSummaryStatus::Draft)
        ->reviewed_by->toBeNull();
})->with(['approve', 'reject']);

it('cannot reach another organization\'s summary through its own project', function (string $action) {
    $this->postJson("/api/projects/{$this->projectA->id}/summaries/{$this->draftB->id}/{$action}")->assertNotFound();

    expect(ProjectSummary::withoutGlobalScopes()->find($this->draftB->id)->status)->toBe(ProjectSummaryStatus::Draft);
})->with(['approve', 'reject']);

it('puts only the target project in the prompt and records usage in its organization', function () {
    $this->postJson("/api/projects/{$this->projectA->id}/summary")->assertStatus(202);

    $this->ai->assertSent(fn (AiRequest $request) => str_contains($request->messages[0]['content'], 'A only'));
    foreach ($this->ai->sent() as $request) {
        expect(json_encode([$request->system, $request->messages]))
            ->not->toContain('B secret')
            ->not->toContain('B confidential');
    }

    expect(AiUsage::withoutGlobalScopes()->pluck('organization_id')->all())->toBe([$this->orgA->id])
        ->and(ProjectSummary::withoutGlobalScopes()->where('project_id', $this->projectA->id)->sole()->organization_id)
        ->toBe($this->orgA->id);
});

it('a user in both organizations still only sees the current one\'s summary', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);

    $this->getJson("/api/projects/{$this->projectB->id}/summary")->assertNotFound();

    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();
    $this->getJson("/api/projects/{$this->projectB->id}/summary")
        ->assertOk()
        ->assertJsonPath('data.summary', 'B summary');
});

it('runs the job in its own organization, not the requester\'s current one', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);
    $pendingB = createSummary($this->orgB, $this->projectB, ProjectSummaryStatus::Pending);
    // ownerA's current organization is still A.
    $this->actingAs($this->ownerA);

    GenerateProjectSummary::dispatch($this->orgB->id, $pendingB->id, $this->ownerA->id);

    expect(ProjectSummary::withoutGlobalScopes()->find($pendingB->id)->status)->toBe(ProjectSummaryStatus::Draft)
        ->and(AiUsage::withoutGlobalScopes()->pluck('organization_id')->all())->toBe([$this->orgB->id])
        // Back in the requester's organization afterwards.
        ->and(app(CurrentOrganization::class)->id())->toBe($this->orgA->id);
    $this->ai->assertSent(fn (AiRequest $request) => ! str_contains($request->messages[0]['content'], 'A only'));
});

it('does nothing when the job\'s organization and summary don\'t match', function () {
    $pendingB = createSummary($this->orgB, $this->projectB, ProjectSummaryStatus::Pending);

    GenerateProjectSummary::dispatch($this->orgA->id, $pendingB->id);

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->find($pendingB->id)->status)->toBe(ProjectSummaryStatus::Pending)
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(0);
});

it('a user in both organizations can only edit the current one\'s draft', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);

    $this->postJson("/api/projects/{$this->projectB->id}/summaries/{$this->draftB->id}/approve", ['summary' => 'Mine'])->assertNotFound();
    expect(ProjectSummary::withoutGlobalScopes()->find($this->draftB->id))
        ->status->toBe(ProjectSummaryStatus::Draft)
        ->summary->toBe('B summary');

    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();
    $this->postJson("/api/projects/{$this->projectB->id}/summaries/{$this->draftB->id}/approve", ['summary' => 'Mine'])
        ->assertOk()
        ->assertJsonPath('data.summary', 'Mine');
});
