<?php

use App\Enums\DefaultRole;
use App\Models\Project;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    $this->projectA = inOrganization($this->orgA, fn () => Project::factory()->create(['name' => 'A plan']));
    $this->projectB = inOrganization($this->orgB, fn () => Project::factory()->create(['name' => 'B secret', 'description' => 'B only']));

    // Org A's owner is the attacker in every test.
    Sanctum::actingAs($this->ownerA);
});

it('never lists another organization\'s projects, even when searching for them', function () {
    expect($this->getJson('/api/projects')->json('data.*.name'))->toBe(['A plan'])
        ->and($this->getJson('/api/projects?search=secret')->json('data'))->toBe([]);
});

it('cannot read another organization\'s project', function () {
    $this->getJson("/api/projects/{$this->projectB->id}")->assertNotFound();
});

it('cannot update another organization\'s project', function () {
    $this->patchJson("/api/projects/{$this->projectB->id}", ['name' => 'Hacked'])->assertNotFound();

    expect($this->projectB->fresh()->name)->toBe('B secret');
});

it('cannot delete another organization\'s project', function () {
    $this->deleteJson("/api/projects/{$this->projectB->id}")->assertNotFound();

    expect(Project::withoutGlobalScopes()->find($this->projectB->id))->not->toBeNull();
});

it('cannot reach another organization\'s project even as a member of both, without switching', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);

    // Still acting in Org A.
    $this->getJson("/api/projects/{$this->projectB->id}")->assertNotFound();

    // After switching, it's visible (and Org A's isn't).
    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();
    $this->getJson("/api/projects/{$this->projectB->id}")->assertOk();
    $this->getJson("/api/projects/{$this->projectA->id}")->assertNotFound();
});
