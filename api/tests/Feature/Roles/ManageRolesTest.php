<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner);
});

it('gives new organizations the default roles', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/roles')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Owner')
        ->assertJsonPath('data.0.is_owner', true)
        ->assertJsonPath('data.0.permissions', array_map(fn (Permission $p) => $p->value, Permission::cases()))
        ->assertJsonPath('data.0.members_count', 1)
        ->assertJsonPath('data.1.name', 'Admin')
        ->assertJsonPath('data.2.name', 'Member')
        ->assertJsonPath('data.2.permissions', ['projects.view', 'projects.create', 'projects.update_own']);
});

it('lists roles for every member', function (DefaultRole $role) {
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->getJson('/api/roles')->assertOk()->assertJsonCount(3, 'data');
})->with([DefaultRole::Admin, DefaultRole::Member]);

it('serves the permission catalogue', function () {
    Sanctum::actingAs(addMember($this->acme));

    $this->getJson('/api/permissions')
        ->assertOk()
        ->assertJsonCount(count(Permission::cases()), 'data')
        ->assertJsonPath('data.0', ['value' => 'projects.view', 'group' => 'Projects', 'label' => 'View projects']);
});

it('lets the owner create, edit and delete a role', function () {
    Sanctum::actingAs($this->owner);

    $id = $this->postJson('/api/roles', ['name' => ' Viewer ', 'permissions' => ['projects.view']])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Viewer')
        ->assertJsonPath('data.is_owner', false)
        ->assertJsonPath('data.members_count', 0)
        ->json('data.id');

    // Stored de-duplicated in catalogue order.
    $this->patchJson("/api/roles/{$id}", ['name' => 'Editor', 'permissions' => ['projects.update_any', 'projects.view']])
        ->assertOk()
        ->assertJsonPath('data.name', 'Editor')
        ->assertJsonPath('data.permissions', ['projects.view', 'projects.update_any']);

    $this->deleteJson("/api/roles/{$id}")->assertNoContent();

    expect(Role::withoutGlobalScope(OrganizationScope::class)->find($id))->toBeNull();
});

it('allows a role without permissions', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/roles', ['name' => 'Guest', 'permissions' => []])
        ->assertCreated()
        ->assertJsonPath('data.permissions', []);
});

it('validates name and permissions', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/roles', ['name' => '', 'permissions' => ['projects.fly']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'permissions.0']);

    $this->postJson('/api/roles', ['name' => 'Viewer'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);

    $this->postJson('/api/roles', ['name' => 'Viewer', 'permissions' => ['projects.view', 'projects.view']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0']);
});

it('keeps role names unique per organization, ignoring case', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/roles', ['name' => 'admin', 'permissions' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    // Renaming a role to its own name is fine.
    $member = roleOf($this->acme, DefaultRole::Member);
    $this->patchJson("/api/roles/{$member->id}", ['name' => 'MEMBER', 'permissions' => ['projects.view']])->assertOk();

    // Another organization may use the same name.
    [$other] = userInOrganization(DefaultRole::Owner, 'Other Corp');
    Sanctum::actingAs($other);
    $this->postJson('/api/roles', ['name' => 'Viewer', 'permissions' => []])->assertCreated();
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/roles', ['name' => 'Viewer', 'permissions' => []])->assertCreated();
});

it('never changes or deletes the owner role', function () {
    Sanctum::actingAs($this->owner);
    $owner = roleOf($this->acme, DefaultRole::Owner);

    $this->patchJson("/api/roles/{$owner->id}", ['name' => 'Boss', 'permissions' => []])->assertForbidden();
    $this->deleteJson("/api/roles/{$owner->id}")->assertForbidden();

    expect($owner->fresh()->name)->toBe('Owner');
});

it('refuses to delete a role that members or pending invitations use', function () {
    Sanctum::actingAs($this->owner);
    $viewer = createRole($this->acme, 'Viewer', [Permission::ProjectsView]);

    $member = addMember($this->acme, $viewer);
    $this->deleteJson("/api/roles/{$viewer->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role' => 'This role is used by 1 member and 0 pending invitations. Give them another role first.']);

    $this->acme->members()->detach($member->id);
    [$invitation] = createInvitation($this->acme, 'new@example.com', $viewer);
    $this->deleteJson("/api/roles/{$viewer->id}")->assertUnprocessable();

    // Expired invitations don't block it; they just lose their role and can't be used.
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();
    $this->deleteJson("/api/roles/{$viewer->id}")->assertNoContent();

    expect($invitation->refresh()->role_id)->toBeNull()
        ->and($invitation->isUsable())->toBeFalse();
});

it('makes an invitation unusable once its role is gone', function () {
    $viewer = createRole($this->acme, 'Viewer', [Permission::ProjectsView]);
    [$invitation, $token] = createInvitation($this->acme, 'new@example.com', $viewer, ['expires_at' => now()->subDay()]);
    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/roles/{$viewer->id}")->assertNoContent();

    // Even with a fresh expiry, a role-less invitation can't be accepted.
    $invitation->refresh()->forceFill(['expires_at' => now()->addDay()])->save();
    $invitee = User::factory()->create(['email' => 'new@example.com']);
    Sanctum::actingAs($invitee);

    $this->postJson("/api/join/{$token}")->assertNotFound();
    expect($invitee->isMemberOf($this->acme))->toBeFalse();
});
