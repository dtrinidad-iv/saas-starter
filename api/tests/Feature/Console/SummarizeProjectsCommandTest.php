<?php

use App\Ai\AiRequest;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\ProjectSummaryStatus;
use App\Models\AiUsage;
use App\Models\Project;
use App\Models\ProjectSummary;
use App\Models\User;
use App\Support\CurrentOrganization;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5]);
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');
});

function projectIn($organization, ?User $creator, ?string $description = 'Something to summarize'): Project
{
    return inOrganization($organization, function () use ($creator, $description) {
        $project = Project::factory()->make(['description' => $description]);
        $project->created_by = $creator?->id;
        $project->save();

        return $project;
    });
}

/**
 * @return list<int>
 */
function summarizedProjectIds(): array
{
    return ProjectSummary::withoutGlobalScopes()->orderBy('project_id')->pluck('project_id')->all();
}

it('summarizes projects with a description and no summary, as their creator', function () {
    $todo = projectIn($this->orgA, $this->ownerA);
    projectIn($this->orgA, $this->ownerA, null);
    $hasOne = projectIn($this->orgA, $this->ownerA);
    createSummary($this->orgA, $hasOne, ProjectSummaryStatus::Rejected);

    $this->artisan('ai:summarize-projects')
        ->expectsOutputToContain('Org A (#'.$this->orgA->id.'): queued 1.')
        ->expectsOutputToContain('Queued 1 summaries.')
        ->assertSuccessful();

    $summary = ProjectSummary::withoutGlobalScopes()->where('project_id', $todo->id)->sole();
    expect($summary)
        ->organization_id->toBe($this->orgA->id)
        ->status->toBe(ProjectSummaryStatus::Draft)
        ->requested_by->toBe($this->ownerA->id)
        ->and(AiUsage::withoutGlobalScopes()->sole()->user_id)->toBe($this->ownerA->id)
        ->and(app(CurrentOrganization::class)->get())->toBeNull();

    // The creator didn't ask for it: the audit entry is a system one.
    $entry = auditLogOf($this->orgA)->where('action', AuditAction::SummaryRequested)->sole();
    expect($entry->actor_id)->toBeNull()
        ->and($entry->metadata)->toEqual(['summary_id' => $summary->id, 'source' => 'backfill']);
});

it('skips projects whose creator may not use AI, left the organization or is unknown', function () {
    $noAi = addMember($this->orgA, createRole($this->orgA, 'No AI', [Permission::ProjectsView, Permission::ProjectsCreate]));
    $former = addMember($this->orgA);
    projectIn($this->orgA, $noAi);
    projectIn($this->orgA, $former);
    projectIn($this->orgA, null);
    $this->orgA->members()->detach($former);

    $this->artisan('ai:summarize-projects')
        ->expectsOutputToContain('Org A (#'.$this->orgA->id.'): nothing to queue, skipped 3 whose creator can no longer use AI.')
        ->assertSuccessful();

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->count())->toBe(0);
});

it('summarizes nothing in an organization that took ai.use away from every role', function () {
    $member = addMember($this->orgA);
    projectIn($this->orgA, $member);
    inOrganization($this->orgA, fn () => roleOf($this->orgA, DefaultRole::Member)->update([
        'permissions' => roleOf($this->orgA, DefaultRole::Member)->permissions->reject(fn ($p) => $p === Permission::AiUse)->values(),
    ]));

    $this->artisan('ai:summarize-projects', ['--organization' => [$this->orgA->id]])->assertSuccessful();

    $this->ai->assertNothingSent();
});

it('keeps each organization\'s projects in their own organization and prompt', function () {
    $a = projectIn($this->orgA, $this->ownerA, 'A only');
    $b = projectIn($this->orgB, $this->ownerB, 'B confidential');

    $this->artisan('ai:summarize-projects')->assertSuccessful();

    expect(ProjectSummary::withoutGlobalScopes()->where('project_id', $a->id)->sole()->organization_id)->toBe($this->orgA->id)
        ->and(ProjectSummary::withoutGlobalScopes()->where('project_id', $b->id)->sole()->organization_id)->toBe($this->orgB->id);

    $prompts = collect($this->ai->sent())->map(fn (AiRequest $request) => json_encode([$request->system, $request->messages]));
    expect($prompts)->toHaveCount(2)
        ->and($prompts->filter(fn ($p) => str_contains($p, 'A only') && ! str_contains($p, 'B confidential')))->toHaveCount(1)
        ->and($prompts->filter(fn ($p) => str_contains($p, 'B confidential') && ! str_contains($p, 'A only')))->toHaveCount(1)
        ->and(AiUsage::withoutGlobalScopes()->orderBy('organization_id')->pluck('organization_id')->all())
        ->toBe([$this->orgA->id, $this->orgB->id]);
});

it('only touches the organizations it is given', function () {
    projectIn($this->orgA, $this->ownerA);
    $b = projectIn($this->orgB, $this->ownerB);

    $this->artisan('ai:summarize-projects', ['--organization' => [$this->orgB->id]])->assertSuccessful();

    expect(summarizedProjectIds())->toBe([$b->id]);
});

it('stops at the limit across organizations', function () {
    $a1 = projectIn($this->orgA, $this->ownerA);
    $a2 = projectIn($this->orgA, $this->ownerA);
    projectIn($this->orgB, $this->ownerB);

    $this->artisan('ai:summarize-projects', ['--limit' => 2])
        ->expectsOutputToContain('Queued 2 summaries.')
        ->assertSuccessful();

    expect(summarizedProjectIds())->toBe([$a1->id, $a2->id]);
});

it('rejects a limit below 1', function () {
    $this->artisan('ai:summarize-projects', ['--limit' => 0])->assertFailed();

    $this->ai->assertNothingSent();
});

it('only counts on a dry run', function () {
    projectIn($this->orgA, $this->ownerA);
    projectIn($this->orgB, $this->ownerB);

    $this->artisan('ai:summarize-projects', ['--dry-run' => true])
        ->expectsOutputToContain('Org A (#'.$this->orgA->id.'): would queue 1.')
        ->expectsOutputToContain('Would queue 2 summaries.')
        ->assertSuccessful();

    $this->ai->assertNothingSent();
    expect(ProjectSummary::withoutGlobalScopes()->count())->toBe(0);
});

it('skips an organization whose budget is used up and carries on with the others', function () {
    projectIn($this->orgA, $this->ownerA);
    $b = projectIn($this->orgB, $this->ownerB);
    createAiUsage($this->orgA, ['cost_usd' => '5.000000']);

    $this->artisan('ai:summarize-projects')
        ->expectsOutputToContain('Org A (#'.$this->orgA->id.'): skipped, the monthly AI budget is used up.')
        ->assertSuccessful();

    expect(summarizedProjectIds())->toBe([$b->id]);
});

it('stops an organization once its budget runs out partway', function () {
    // Each fake call (100 input tokens) costs $3 against the $5 budget: two fit, the third doesn't.
    config(['ai.pricing.'.config('ai.model') => ['input' => 30_000, 'output' => 0]]);
    $first = projectIn($this->orgA, $this->ownerA);
    $second = projectIn($this->orgA, $this->ownerA);
    projectIn($this->orgA, $this->ownerA);
    $b = projectIn($this->orgB, $this->ownerB);

    $this->artisan('ai:summarize-projects')
        ->expectsOutputToContain('Org A (#'.$this->orgA->id.'): queued 2, then stopped: the monthly AI budget is used up.')
        ->expectsOutputToContain('Org B (#'.$this->orgB->id.'): queued 1.')
        ->assertSuccessful();

    expect(summarizedProjectIds())->toBe([$first->id, $second->id, $b->id]);
});
