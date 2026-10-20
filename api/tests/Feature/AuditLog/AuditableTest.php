<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
    Sanctum::actingAs($this->owner);
});

it('records project create, update and delete with the changed fields', function () {
    $id = $this->postJson('/api/projects', ['name' => 'Launch', 'description' => 'First'])->assertCreated()->json('data.id');
    $this->patchJson("/api/projects/{$id}", ['name' => 'Launch 2'])->assertOk();
    $this->deleteJson("/api/projects/{$id}")->assertNoContent();

    [$created, $updated, $deleted] = auditLogOf($this->acme)->filter(fn (AuditLog $entry) => $entry->subject_type === 'project'
        && str_starts_with($entry->action->value, 'project.'))->values()->all();

    expect($created)
        ->action->value->toBe('project.created')
        ->actor_id->toBe($this->owner->id)
        ->subject_type->toBe('project')
        ->subject_id->toBe($id)
        ->ip_address->toBe('127.0.0.1')
        ->changes->toEqual([
            'name' => ['old' => null, 'new' => 'Launch'],
            'description' => ['old' => null, 'new' => 'First'],
        ])
        ->and($updated)
        ->action->value->toBe('project.updated')
        ->changes->toEqual(['name' => ['old' => 'Launch', 'new' => 'Launch 2']])
        ->and($deleted)
        ->action->value->toBe('project.deleted')
        ->subject_label->toBe('Launch 2')
        ->changes->name->toEqual(['old' => 'Launch 2', 'new' => null]);
});

it('never records keys or timestamps', function () {
    $this->postJson('/api/projects', ['name' => 'Launch'])->assertCreated();

    expect(auditLogOf($this->acme)->sole()->changes)
        ->not->toHaveKeys(['id', 'organization_id', 'created_at', 'updated_at', 'created_by']);
});

it('records nothing for an update that changes nothing', function () {
    $id = $this->postJson('/api/projects', ['name' => 'Launch'])->assertCreated()->json('data.id');
    $this->patchJson("/api/projects/{$id}", ['name' => 'Launch'])->assertOk();

    expect(auditLogOf($this->acme))->toHaveCount(1);
});

it('shortens long values', function () {
    $this->postJson('/api/projects', ['name' => 'Long', 'description' => str_repeat('a', 2000)])->assertCreated();

    expect(auditLogOf($this->acme)->firstWhere('action.value', 'project.created')->changes['description']['new'])->toBe(str_repeat('a', 500).'…');
});

it('records an edit past the shortened part of a long value', function () {
    $id = $this->postJson('/api/projects', ['name' => 'Long', 'description' => str_repeat('a', 600)])->assertCreated()->json('data.id');
    $this->patchJson("/api/projects/{$id}", ['description' => str_repeat('a', 600).'b'])->assertOk();

    expect(auditLogOf($this->acme)->last())
        ->action->value->toBe('project.updated')
        ->changes->description->new->toBe(str_repeat('a', 500).'…');
});

it('records role permission changes as lists of permissions', function () {
    $id = $this->postJson('/api/roles', ['name' => 'Editor', 'permissions' => ['projects.view']])->assertCreated()->json('data.id');
    $this->patchJson("/api/roles/{$id}", ['name' => 'Editor', 'permissions' => ['projects.view', 'projects.create']])->assertOk();
    $this->deleteJson("/api/roles/{$id}")->assertNoContent();

    expect(auditLogOf($this->acme)->map(fn (AuditLog $entry) => $entry->action->value)->all())
        ->toBe(['role.created', 'role.updated', 'role.deleted'])
        ->and(auditLogOf($this->acme)[1]->changes)
        ->toEqual(['permissions' => ['old' => ['projects.view'], 'new' => ['projects.view', 'projects.create']]]);
});

it('records one entry for a new organization, not its default roles', function () {
    $id = $this->postJson('/api/organizations', ['name' => 'Beta'])->assertCreated()->json('data.id');

    $entries = AuditLog::withoutGlobalScopes()->where('organization_id', $id)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->sole())
        ->action->value->toBe('organization.created')
        ->actor_id->toBe($this->owner->id)
        ->subject_label->toBe('Beta')
        ->and(auditLogOf($this->acme))->toBeEmpty();
});

it('records the organization created on registration', function () {
    $this->postJson('/api/register', [
        'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertCreated();

    $ada = User::where('email', 'ada@example.com')->sole();

    expect(AuditLog::withoutGlobalScopes()->where('organization_id', $ada->current_organization_id)->sole())
        ->action->value->toBe('organization.created')
        ->actor_id->toBe($ada->id);
});

it('can be turned off for a model', function () {
    inOrganization($this->acme, fn () => Role::withoutAuditing(
        fn () => Role::create(['name' => 'Quiet', 'permissions' => [Permission::ProjectsView]]),
    ));

    expect(auditLogOf($this->acme))->toBeEmpty();
});

it('leaves no entry when the change is rolled back', function () {
    $member = addMember($this->acme, DefaultRole::Member);
    $role = roleOf($this->acme, DefaultRole::Member);

    // Deleting a role in use fails inside DeleteRole's transaction.
    $this->deleteJson("/api/roles/{$role->id}")->assertUnprocessable();

    expect(auditLogOf($this->acme))->toBeEmpty()
        ->and($member->fresh())->not->toBeNull();
});
