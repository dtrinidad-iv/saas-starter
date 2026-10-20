<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
});

it('lets roles with audit_log.view read the log', function (DefaultRole $role, int $status) {
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->getJson('/api/audit-logs')->assertStatus($status);
    $this->getJson('/api/audit-log/actions')->assertStatus($status);
})->with([
    'owner' => [DefaultRole::Owner, 200],
    'admin' => [DefaultRole::Admin, 200],
    'member' => [DefaultRole::Member, 403],
]);

it('follows the permission on custom roles', function () {
    $auditor = createRole($this->acme, 'Auditor', [Permission::AuditLogView]);
    $viewer = createRole($this->acme, 'Viewer', [Permission::ProjectsView]);

    Sanctum::actingAs(addMember($this->acme, $auditor));
    $this->getJson('/api/audit-logs')->assertOk();

    Sanctum::actingAs(addMember($this->acme, $viewer));
    $this->getJson('/api/audit-logs')->assertForbidden();
});

it('lists the permission in the catalogue', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/permissions')
        ->assertOk()
        ->assertJsonFragment(['value' => 'audit_log.view', 'group' => 'Organization', 'label' => 'View the audit log']);
});

it('grants audit_log.view to existing Admin roles only', function () {
    $admin = roleOf($this->acme, DefaultRole::Admin);
    $member = roleOf($this->acme, DefaultRole::Member);
    $renamed = createRole($this->acme, 'Administrators', [Permission::ProjectsView]);
    DB::table('roles')->where('id', $admin->id)->update(['permissions' => json_encode(['projects.view'])]);

    $migration = require database_path('migrations/2026_10_20_160001_grant_audit_log_view_to_admin_roles.php');
    $migration->up();
    $migration->up(); // idempotent

    expect($admin->fresh()->permissions->map->value->all())->toBe(['projects.view', 'audit_log.view'])
        ->and($member->fresh()->has(Permission::AuditLogView))->toBeFalse()
        ->and($renamed->fresh()->has(Permission::AuditLogView))->toBeFalse();
});
