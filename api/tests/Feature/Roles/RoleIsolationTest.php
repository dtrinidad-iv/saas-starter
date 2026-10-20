<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Support\CurrentOrganization;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');
    $this->roleB = createRole($this->orgB, 'Secret B role', [Permission::ProjectsView]);
});

it('lists only the current organization\'s roles', function () {
    Sanctum::actingAs($this->ownerA);

    $this->getJson('/api/roles')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertDontSee('Secret B role');
});

it('cannot edit or delete another organization\'s role', function () {
    Sanctum::actingAs($this->ownerA);

    $this->patchJson("/api/roles/{$this->roleB->id}", ['name' => 'Hijacked', 'permissions' => []])->assertNotFound();
    $this->deleteJson("/api/roles/{$this->roleB->id}")->assertNotFound();

    expect($this->roleB->fresh()->name)->toBe('Secret B role');
});

it('cannot assign or invite with another organization\'s role', function () {
    $member = addMember($this->orgA);
    Sanctum::actingAs($this->ownerA);

    $this->patchJson("/api/members/{$member->id}", ['role_id' => $this->roleB->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);
    $this->postJson('/api/invitations', ['email' => 'x@example.com', 'role_id' => $this->roleB->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);
});

it('cannot reach org B\'s roles while acting in org A, even as a member of both', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);
    $memberA = addMember($this->orgA);
    Sanctum::actingAs($this->ownerA); // current organization stays A

    $this->getJson('/api/roles')->assertOk()->assertDontSee('Secret B role');
    $this->patchJson("/api/roles/{$this->roleB->id}", ['name' => 'Hijacked', 'permissions' => []])->assertNotFound();
    $this->deleteJson("/api/roles/{$this->roleB->id}")->assertNotFound();
    $this->patchJson("/api/members/{$memberA->id}", ['role_id' => $this->roleB->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);
    $this->postJson('/api/invitations', ['email' => 'x@example.com', 'role_id' => $this->roleB->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);

    expect($this->roleB->fresh()->name)->toBe('Secret B role');
});

it('is enforced by the database too', function () {
    $member = addMember($this->orgA);
    [$invitation] = createInvitation($this->orgA, 'x@example.com');

    expect(fn () => $this->orgA->members()->updateExistingPivot($member->id, ['role_id' => $this->roleB->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => $invitation->forceFill(['role_id' => $this->roleB->id])->save())
        ->toThrow(QueryException::class);
});

it('refuses to act in an organization with another organization\'s role', function () {
    expect(fn () => app(CurrentOrganization::class)->set($this->orgA, $this->roleB))
        ->toThrow(LogicException::class);
});

it('uses the role of the current organization when the user belongs to several', function () {
    // Owner in A, Viewer in B.
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->roleB->id]);
    Sanctum::actingAs($this->ownerA);

    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk()->assertJsonPath('data.permissions', ['projects.view']);
    $this->postJson('/api/projects', ['name' => 'In B'])->assertForbidden();
    $this->postJson('/api/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();

    $this->postJson("/api/organizations/{$this->orgA->id}/switch")->assertOk();
    $this->postJson('/api/projects', ['name' => 'In A'])->assertCreated();
});
