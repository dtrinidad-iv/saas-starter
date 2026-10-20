<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner);
});

/**
 * A member with a custom role that may manage roles, plus whatever else is given.
 *
 * @param  list<Permission>  $permissions
 */
function roleManager(array $permissions = []): User
{
    $role = createRole(test()->acme, 'Manager', [Permission::RolesManage, ...$permissions]);

    return addMember(test()->acme, $role);
}

it('does not let roles be managed without roles.manage', function (DefaultRole $role) {
    Sanctum::actingAs(addMember($this->acme, $role));
    $member = roleOf($this->acme, DefaultRole::Member);

    $this->postJson('/api/roles', ['name' => 'Viewer', 'permissions' => []])->assertForbidden();
    $this->patchJson("/api/roles/{$member->id}", ['name' => 'Member', 'permissions' => []])->assertForbidden();
    $this->deleteJson("/api/roles/{$member->id}")->assertForbidden();

    expect($member->fresh()->permissions)->toHaveCount(3);
})->with([DefaultRole::Admin, DefaultRole::Member]);

it('lets a role manager grant only permissions they have', function () {
    Sanctum::actingAs(roleManager([Permission::ProjectsView, Permission::ProjectsCreate]));

    $this->postJson('/api/roles', ['name' => 'Viewer', 'permissions' => ['projects.view']])->assertCreated();
    $this->postJson('/api/roles', ['name' => 'Deleter', 'permissions' => ['projects.view', 'projects.delete']])->assertForbidden();
});

it('stops a role manager from editing roles that can do more than they can', function () {
    Sanctum::actingAs(roleManager([Permission::ProjectsView, Permission::ProjectsCreate, Permission::ProjectsUpdateOwn]));
    $admin = roleOf($this->acme, DefaultRole::Admin);
    $member = roleOf($this->acme, DefaultRole::Member);

    // Admin has permissions the manager lacks, even when the edit would remove them.
    $this->patchJson("/api/roles/{$admin->id}", ['name' => 'Admin', 'permissions' => ['projects.view']])->assertForbidden();
    $this->deleteJson("/api/roles/{$admin->id}")->assertForbidden();

    // Member is covered: it can be edited within the manager's own permissions.
    $this->patchJson("/api/roles/{$member->id}", ['name' => 'Member', 'permissions' => ['projects.view']])->assertOk();
    $this->patchJson("/api/roles/{$member->id}", ['name' => 'Member', 'permissions' => ['projects.delete']])->assertForbidden();
});

it('stops a role manager from editing or deleting their own role', function () {
    $manager = roleManager([Permission::ProjectsView]);
    Sanctum::actingAs($manager);
    $own = roleIn($this->acme, $manager);

    $this->patchJson("/api/roles/{$own->id}", ['name' => 'Manager', 'permissions' => ['roles.manage']])->assertForbidden();
    $this->deleteJson("/api/roles/{$own->id}")->assertForbidden();
});

it('lets a custom viewer role read projects but not change them', function () {
    $project = inOrganization($this->acme, fn () => Project::factory()->create(['created_by' => $this->owner->id]));
    $viewer = addMember($this->acme, createRole($this->acme, 'Viewer', [Permission::ProjectsView]));
    Sanctum::actingAs($viewer);

    $this->getJson('/api/projects')->assertOk()->assertJsonPath('data.0.can', ['update' => false, 'delete' => false]);
    $this->getJson("/api/projects/{$project->id}")->assertOk();
    $this->postJson('/api/projects', ['name' => 'New'])->assertForbidden();
    $this->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson("/api/projects/{$project->id}")->assertForbidden();
    $this->postJson('/api/invitations', ['email' => 'x@example.com', 'role_id' => roleOf($this->acme, DefaultRole::Member)->id])->assertForbidden();
    $this->getJson('/api/invitations')->assertForbidden();
});

it('hides projects from a role without projects.view', function () {
    Sanctum::actingAs(addMember($this->acme, createRole($this->acme, 'Guest', [])));

    $this->getJson('/api/projects')->assertForbidden();
    // Members and roles stay visible to everyone in the organization.
    $this->getJson('/api/members')->assertOk();
    $this->getJson('/api/roles')->assertOk();
});

it('gives a role\'s new permissions to its members right away', function () {
    $viewerRole = createRole($this->acme, 'Viewer', [Permission::ProjectsView]);
    $viewer = addMember($this->acme, $viewerRole);

    Sanctum::actingAs($this->owner);
    $this->patchJson("/api/roles/{$viewerRole->id}", ['name' => 'Viewer', 'permissions' => ['projects.view', 'projects.create']])->assertOk();

    Sanctum::actingAs($viewer);
    $this->postJson('/api/projects', ['name' => 'New'])->assertCreated();
});

it('shows the current user\'s role and permissions with the organization', function () {
    $viewer = addMember($this->acme, createRole($this->acme, 'Viewer', [Permission::ProjectsView]));
    Sanctum::actingAs($viewer);

    $this->getJson('/api/organizations')
        ->assertOk()
        ->assertJsonPath('data.0.role.name', 'Viewer')
        ->assertJsonPath('data.0.permissions', ['projects.view']);
});

it('only lets people assign and invite roles they cover', function () {
    $limited = createRole($this->acme, 'Recruiter', [Permission::ProjectsView, Permission::MembersInvite, Permission::MembersChangeRole]);
    $viewerRole = createRole($this->acme, 'Viewer', [Permission::ProjectsView]);
    $recruiter = addMember($this->acme, $limited);
    $viewer = addMember($this->acme, $viewerRole);
    $member = addMember($this->acme);
    Sanctum::actingAs($recruiter);

    $this->postJson('/api/invitations', ['email' => 'v@example.com', 'role_id' => $viewerRole->id])->assertCreated();
    // Member can create and edit projects; the recruiter can't.
    $this->postJson('/api/invitations', ['email' => 'm@example.com', 'role_id' => roleOf($this->acme, DefaultRole::Member)->id])->assertForbidden();
    $this->patchJson("/api/members/{$viewer->id}", ['role_id' => roleOf($this->acme, DefaultRole::Member)->id])->assertForbidden();
    $this->patchJson("/api/members/{$member->id}", ['role_id' => $viewerRole->id])->assertForbidden();
    $this->patchJson("/api/members/{$viewer->id}", ['role_id' => $limited->id])->assertOk();
});

it('only lets people revoke or replace invitations for roles they cover', function () {
    $recruiter = addMember($this->acme, createRole($this->acme, 'Recruiter', [Permission::ProjectsView, Permission::MembersInvite]));
    $viewerRole = createRole($this->acme, 'Viewer', [Permission::ProjectsView]);
    [$ownerInvite] = createInvitation($this->acme, 'cfo@example.com', DefaultRole::Owner);
    [$viewerInvite] = createInvitation($this->acme, 'intern@example.com', $viewerRole);
    Mail::fake();
    Sanctum::actingAs($recruiter);

    $this->deleteJson("/api/invitations/{$ownerInvite->id}")->assertForbidden();
    $this->postJson('/api/invitations', ['email' => 'cfo@example.com', 'role_id' => $viewerRole->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'This address already has a pending invitation for a role you can\'t give.']);
    expect($ownerInvite->fresh())->not->toBeNull()
        ->and($ownerInvite->fresh()->role_id)->toBe(roleOf($this->acme, DefaultRole::Owner)->id);

    // Their own level is fine.
    $this->postJson('/api/invitations', ['email' => 'intern@example.com', 'role_id' => $viewerRole->id])->assertCreated();
    $newInvite = OrganizationInvitation::withoutGlobalScopes()->where('email', 'intern@example.com')->sole();
    expect($newInvite->id)->not->toBe($viewerInvite->id);
    $this->deleteJson("/api/invitations/{$newInvite->id}")->assertNoContent();
});

it('stops a role manager from editing a peer role with the same permissions', function () {
    $manager = roleManager([Permission::ProjectsView]);
    $peer = createRole($this->acme, 'Co-manager', [Permission::ProjectsView, Permission::RolesManage]);
    addMember($this->acme, $peer);
    Sanctum::actingAs($manager);

    // Emptying it would remove the peer, which they couldn't do directly.
    $this->patchJson("/api/roles/{$peer->id}", ['name' => 'Co-manager', 'permissions' => []])->assertForbidden();
    $this->deleteJson("/api/roles/{$peer->id}")->assertForbidden();
});

it('needs projects.view to change projects', function () {
    $project = inOrganization($this->acme, fn () => Project::factory()->create(['created_by' => $this->owner->id]));
    Sanctum::actingAs(addMember($this->acme, createRole($this->acme, 'Blind editor', [Permission::ProjectsUpdateAny, Permission::ProjectsDelete])));

    $this->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson("/api/projects/{$project->id}")->assertForbidden();
});
