<?php

use App\Enums\DefaultRole;
use App\Models\Project;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner);
    $this->admin = addMember($this->acme, DefaultRole::Admin);
    $this->member = addMember($this->acme, DefaultRole::Member);
    $this->otherMember = addMember($this->acme, DefaultRole::Member);

    $this->ownProject = inOrganization($this->acme, fn () => Project::factory()->create(['created_by' => $this->member->id]));
    $this->othersProject = inOrganization($this->acme, fn () => Project::factory()->create(['created_by' => $this->otherMember->id]));
});

function actor(string $who)
{
    return test()->{$who};
}

it('lets every role see and create projects', function (string $who) {
    Sanctum::actingAs(actor($who));

    $this->getJson('/api/projects')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/projects/{$this->othersProject->id}")->assertOk();
    $this->postJson('/api/projects', ['name' => "By {$who}"])->assertCreated();
})->with(['owner', 'admin', 'member']);

it('applies the edit permissions', function (string $who, string $project, bool $allowed) {
    Sanctum::actingAs(actor($who));
    $target = $this->{$project};

    $response = $this->patchJson("/api/projects/{$target->id}", ['name' => 'Renamed']);

    $allowed ? $response->assertOk() : $response->assertForbidden();
    expect($target->fresh()->name === 'Renamed')->toBe($allowed);
})->with([
    'owner edits any' => ['owner', 'othersProject', true],
    'admin edits any' => ['admin', 'othersProject', true],
    'member edits own' => ['member', 'ownProject', true],
    'member edits someone else\'s' => ['member', 'othersProject', false],
]);

it('applies the delete permissions', function (string $who, string $project, bool $allowed) {
    Sanctum::actingAs(actor($who));
    $target = $this->{$project};

    $response = $this->deleteJson("/api/projects/{$target->id}");

    $allowed ? $response->assertNoContent() : $response->assertForbidden();
    expect(Project::withoutGlobalScopes()->find($target->id) === null)->toBe($allowed);
})->with([
    'owner deletes' => ['owner', 'othersProject', true],
    'admin deletes' => ['admin', 'othersProject', true],
    'member deletes own' => ['member', 'ownProject', false],
    'member deletes someone else\'s' => ['member', 'othersProject', false],
]);

it('reports the permissions per project', function () {
    Sanctum::actingAs($this->member);

    $rows = collect($this->getJson('/api/projects')->json('data'))->keyBy('id');

    expect($rows[$this->ownProject->id]['can'])->toBe(['update' => true, 'delete' => false, 'chat' => true])
        ->and($rows[$this->othersProject->id]['can'])->toBe(['update' => false, 'delete' => false, 'chat' => true]);
});

it('loses edit rights on own projects once demoted from admin', function () {
    $project = inOrganization($this->acme, fn () => Project::factory()->create(['created_by' => $this->owner->id]));
    Sanctum::actingAs($this->admin);
    $this->patchJson("/api/projects/{$project->id}", ['name' => 'As admin'])->assertOk();

    $this->acme->members()->updateExistingPivot($this->admin->id, ['role_id' => roleOf($this->acme, DefaultRole::Member)->id]);

    $this->patchJson("/api/projects/{$project->id}", ['name' => 'As member'])->assertForbidden();
});
