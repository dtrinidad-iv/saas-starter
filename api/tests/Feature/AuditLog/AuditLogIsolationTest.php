<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\Role;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');
});

it('lists only the current organization\'s entries', function () {
    Sanctum::actingAs($this->ownerA);
    $this->postJson('/api/projects', ['name' => 'Secret A plan'])->assertCreated();

    Sanctum::actingAs($this->ownerB);
    $this->getJson('/api/audit-logs')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertDontSee('Secret A plan');

    $this->getJson("/api/audit-logs?actor_id={$this->ownerA->id}")->assertOk()->assertJsonCount(0, 'data');
});

it('writes model changes to the organization they happened in', function () {
    inOrganization($this->orgA, fn () => Project::factory()->create(['name' => 'In A']));

    // No logged-in user (like a job): no actor or IP.
    expect(auditLogOf($this->orgA)->sole())
        ->subject_label->toBe('In A')
        ->actor_id->toBeNull()
        ->ip_address->toBeNull()
        ->and(auditLogOf($this->orgB))->toBeEmpty();
});

it('shows org B\'s entries only while acting in org B, even as a member of both', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);
    inOrganization($this->orgB, fn () => Project::factory()->create(['name' => 'Secret B plan']));
    Sanctum::actingAs($this->ownerA); // current organization stays A

    $this->getJson('/api/audit-logs')->assertOk()->assertDontSee('Secret B plan');

    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();
    $this->getJson('/api/audit-logs')->assertOk()->assertSee('Secret B plan');
});

it('records joining in the invitation\'s organization, not the joiner\'s current one', function () {
    $this->ownerB->forceFill(['email' => 'b@example.com'])->save();
    [, $token] = createInvitation($this->orgA, 'b@example.com');
    Sanctum::actingAs($this->ownerB);

    $this->postJson("/api/join/{$token}")->assertOk();

    expect(auditLogOf($this->orgA)->sole())
        ->action->value->toBe('member.joined')
        ->actor_id->toBe($this->ownerB->id)
        ->and(auditLogOf($this->orgB))->toBeEmpty();
});

it('refuses to audit a change to another organization\'s record', function () {
    $roleA = createRole($this->orgA, 'Role of A', [Permission::ProjectsView]);

    expect(fn () => inOrganization($this->orgB, fn () => Role::withoutGlobalScopes()->findOrFail($roleA->id)->update(['name' => 'Renamed'])))
        ->toThrow(LogicException::class, 'its organization');

    expect(auditLogOf($this->orgB))->toBeEmpty();
});

it('filters by actor only within the current organization', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);
    Sanctum::actingAs($this->ownerA);
    $this->postJson('/api/projects', ['name' => 'Made in A'])->assertCreated();
    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();
    $this->postJson('/api/projects', ['name' => 'Made in B'])->assertCreated();

    $this->getJson("/api/audit-logs?actor_id={$this->ownerA->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject.label', 'Made in B');
});

it('records leaving and role changes in that organization only, for members of both', function () {
    $both = addMember($this->orgA, DefaultRole::Admin);
    $this->orgB->members()->attach($both, ['role_id' => roleOf($this->orgB, DefaultRole::Member)->id]);
    $member = addMember($this->orgA, DefaultRole::Member);
    Sanctum::actingAs($both); // current organization: A

    $this->patchJson("/api/members/{$member->id}", ['role_id' => roleOf($this->orgA, DefaultRole::Admin)->id])->assertOk();
    $this->deleteJson("/api/members/{$both->id}")->assertNoContent();

    expect($both->fresh()->current_organization_id)->toBe($this->orgB->id)
        ->and(auditLogOf($this->orgA)->map(fn ($entry) => $entry->action->value)->all())->toBe(['member.role_changed', 'member.removed'])
        ->and(auditLogOf($this->orgB))->toBeEmpty();
});

it('hides project details by the viewer\'s permissions in the current organization, not another one', function () {
    Sanctum::actingAs($this->ownerA);
    $this->postJson('/api/projects', ['name' => 'Secret A plan'])->assertCreated();
    // Owner of B (can view projects there), only an auditor in A.
    $this->orgA->members()->attach($this->ownerB, ['role_id' => createRole($this->orgA, 'Auditor', [Permission::AuditLogView])->id]);
    $this->ownerB->forceFill(['current_organization_id' => $this->orgA->id])->save();

    Sanctum::actingAs($this->ownerB);
    $this->getJson('/api/audit-logs?action=project.created')
        ->assertOk()
        ->assertJsonPath('data.0.subject.label', null)
        ->assertJsonPath('data.0.changes', [])
        ->assertDontSee('Secret A plan');
});
