<?php

use App\Ai\Prompts\ProjectSummaryPrompt;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\ProjectSummaryStatus;
use App\Jobs\GenerateProjectSummary;
use App\Models\Project;
use App\Models\ProjectSummary;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
    $this->project = inOrganization($this->acme, fn () => Project::factory()->create([
        'name' => 'Website relaunch',
        'description' => 'Rebuild the marketing site and move the blog.',
    ]));
    Sanctum::actingAs($this->owner);
});

it('records which project text a generated summary was written from', function () {
    $this->postJson("/api/projects/{$this->project->id}/summary")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.stale', false);

    expect(ProjectSummary::withoutGlobalScopes()->sole()->source_hash)
        ->toBe(ProjectSummaryPrompt::sourceHash($this->project));
});

it('hashes the project as it was summarized, not as it was when requested', function () {
    Queue::fake();
    $this->postJson("/api/projects/{$this->project->id}/summary")->assertStatus(202);
    $this->patchJson("/api/projects/{$this->project->id}", ['description' => 'Changed before the job ran.'])->assertOk();

    Queue::assertPushed(GenerateProjectSummary::class, function (GenerateProjectSummary $job) {
        // Run it now, in its organization, as the worker would.
        inOrganization($this->acme, fn () => app()->call([$job, 'handle']));

        return true;
    });

    expect(ProjectSummary::withoutGlobalScopes()->sole())
        ->status->toBe(ProjectSummaryStatus::Draft)
        ->source_hash->toBe(ProjectSummaryPrompt::sourceHash($this->project->fresh()));
    $this->getJson("/api/projects/{$this->project->id}/summary")->assertJsonPath('data.stale', false);
});

it('marks a summary stale once the project name or description changes', function (array $change) {
    $this->postJson("/api/projects/{$this->project->id}/summary")->assertStatus(202);
    $draft = ProjectSummary::withoutGlobalScopes()->sole();
    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/approve")->assertOk();

    $this->getJson("/api/projects/{$this->project->id}/summary")
        ->assertJsonPath('data.stale', false)
        ->assertJsonPath('approved.stale', false);

    $this->patchJson("/api/projects/{$this->project->id}", $change)->assertOk();

    $this->getJson("/api/projects/{$this->project->id}/summary")
        ->assertJsonPath('data.stale', true)
        ->assertJsonPath('approved.stale', true);

    // Nothing is regenerated on its own.
    expect(ProjectSummary::withoutGlobalScopes()->count())->toBe(1);
})->with([
    'name' => [['name' => 'Website relaunch v2']],
    'description' => [['description' => 'Only move the blog.']],
]);

it('does not mark a summary without a source hash as stale', function () {
    createSummary($this->acme, $this->project, ProjectSummaryStatus::Approved);

    $this->getJson("/api/projects/{$this->project->id}/summary")->assertJsonPath('data.stale', false);
});

it('saves the reviewer\'s edits when approving and notes them in the audit log', function () {
    $draft = createSummary($this->acme, $this->project, ProjectSummaryStatus::Draft, ['summary' => 'AI text', 'key_points' => ['One', 'Two']]);

    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/approve", [
        'summary' => '  Edited text.  ',
        'key_points' => ['One', 'Two, fixed'],
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.summary', 'Edited text.')
        ->assertJsonPath('data.key_points', ['One', 'Two, fixed']);

    $entry = auditLogOf($this->acme)->where('action', AuditAction::SummaryApproved)->sole();
    expect($entry->metadata)->toEqual(['summary_id' => $draft->id, 'edited' => true]);
});

it('keeps the draft\'s other field when only one is edited', function () {
    $draft = createSummary($this->acme, $this->project, ProjectSummaryStatus::Draft, ['summary' => 'AI text', 'key_points' => ['One']]);

    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/approve", ['key_points' => []])
        ->assertOk()
        ->assertJsonPath('data.summary', 'AI text')
        ->assertJsonPath('data.key_points', []);
});

it('does not note an edit when approving unchanged text', function (array $payload) {
    $draft = createSummary($this->acme, $this->project, ProjectSummaryStatus::Draft, ['summary' => 'AI text', 'key_points' => ['One']]);

    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/approve", $payload)->assertOk();

    expect(auditLogOf($this->acme)->where('action', AuditAction::SummaryApproved)->sole()->metadata)
        ->toEqual(['summary_id' => $draft->id]);
})->with([
    'no body' => [[]],
    'same text' => [['summary' => 'AI text', 'key_points' => ['One']]],
]);

it('validates edits with the same limits as the AI output', function (array $payload, string $error) {
    $draft = createSummary($this->acme, $this->project, ProjectSummaryStatus::Draft, ['summary' => 'AI text']);

    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/approve", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect(ProjectSummary::withoutGlobalScopes()->find($draft->id))
        ->status->toBe(ProjectSummaryStatus::Draft)
        ->summary->toBe('AI text');
})->with([
    'too many points' => [['key_points' => ['1', '2', '3', '4', '5', '6']], 'key_points'],
    'point too long' => [['key_points' => [str_repeat('a', ProjectSummaryPrompt::MAX_KEY_POINT_LENGTH + 1)]], 'key_points.0'],
    'empty point' => [['key_points' => ['']], 'key_points.0'],
    'summary too long' => [['summary' => str_repeat('a', ProjectSummaryPrompt::MAX_SUMMARY_LENGTH + 1)], 'summary'],
    'empty summary' => [['summary' => '  '], 'summary'],
    'points not a list' => [['key_points' => ['a' => 'One']], 'key_points'],
]);

it('only accepts edits on a draft', function (ProjectSummaryStatus $status) {
    $summary = createSummary($this->acme, $this->project, $status, ['summary' => 'Kept']);

    $this->postJson("/api/projects/{$this->project->id}/summaries/{$summary->id}/approve", ['summary' => 'Changed'])
        ->assertStatus(409);

    expect(ProjectSummary::withoutGlobalScopes()->find($summary->id)->summary)->toBe('Kept');
})->with([ProjectSummaryStatus::Approved, ProjectSummaryStatus::Rejected]);

it('ignores edits sent with a rejection', function () {
    $draft = createSummary($this->acme, $this->project, ProjectSummaryStatus::Draft, ['summary' => 'AI text']);

    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/reject", ['summary' => 'Changed'])
        ->assertOk()
        ->assertJsonPath('data.summary', 'AI text');
});

it('does not let someone who cannot review edit a draft', function () {
    $member = addMember($this->acme, DefaultRole::Member);
    $draft = createSummary($this->acme, $this->project, ProjectSummaryStatus::Draft, ['summary' => 'AI text']);
    Sanctum::actingAs($member);

    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/approve", ['summary' => 'Changed'])
        ->assertForbidden();

    expect(ProjectSummary::withoutGlobalScopes()->find($draft->id)->summary)->toBe('AI text');
});

it('cannot edit another organization\'s draft', function () {
    [, $other] = userInOrganization(DefaultRole::Owner, 'Other');
    $otherProject = inOrganization($other, fn () => Project::factory()->create());
    $draft = createSummary($other, $otherProject, ProjectSummaryStatus::Draft, ['summary' => 'Theirs']);

    $this->postJson("/api/projects/{$otherProject->id}/summaries/{$draft->id}/approve", ['summary' => 'Mine'])->assertNotFound();
    $this->postJson("/api/projects/{$this->project->id}/summaries/{$draft->id}/approve", ['summary' => 'Mine'])->assertNotFound();

    expect(ProjectSummary::withoutGlobalScopes()->find($draft->id))
        ->status->toBe(ProjectSummaryStatus::Draft)
        ->summary->toBe('Theirs');
});
