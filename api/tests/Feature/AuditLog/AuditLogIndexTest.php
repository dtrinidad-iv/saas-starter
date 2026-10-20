<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
    $this->admin = addMember($this->acme, DefaultRole::Admin);
});

it('lists entries newest first with actor, subject and changes', function () {
    Sanctum::actingAs($this->owner);
    $id = $this->postJson('/api/projects', ['name' => 'Launch'])->json('data.id');
    $this->patchJson("/api/projects/{$id}", ['name' => 'Launch 2']);

    $this->getJson('/api/audit-logs')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'project.updated')
        ->assertJsonPath('data.0.action_label', 'Edited a project')
        ->assertJsonPath('data.0.actor', ['id' => $this->owner->id, 'name' => $this->owner->name])
        ->assertJsonPath('data.0.subject', ['type' => 'project', 'id' => $id, 'label' => 'Launch 2'])
        ->assertJsonPath('data.0.changes.name.old', 'Launch')
        ->assertJsonPath('data.0.changes.name.new', 'Launch 2')
        ->assertJsonPath('data.1.action', 'project.created')
        ->assertJsonPath('meta.per_page', 25);
});

it('shows IP addresses to owners only', function () {
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/projects', ['name' => 'Launch'])->assertCreated();

    $this->getJson('/api/audit-logs')->assertOk()->assertJsonPath('data.0.ip_address', '127.0.0.1');

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/audit-logs')->assertOk()->assertJsonMissingPath('data.0.ip_address');
});

it('hides project names and changes from viewers who can\'t view projects', function () {
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/projects', ['name' => 'Secret launch', 'description' => 'Secret details'])->assertCreated();
    $this->patchJson('/api/members/'.$this->admin->id, ['role_id' => roleOf($this->acme, DefaultRole::Member)->id])->assertOk();
    $auditor = addMember($this->acme, createRole($this->acme, 'Auditor', [Permission::AuditLogView]));

    Sanctum::actingAs($auditor);
    $this->getJson('/api/audit-logs?action=project.created')
        ->assertOk()
        ->assertJsonPath('data.0.subject', ['type' => 'project', 'id' => auditLogOf($this->acme)->first()->subject_id, 'label' => null])
        ->assertJsonPath('data.0.changes', [])
        ->assertDontSee('Secret');
    // Summary entries are about the project too (the description triggered one).
    $this->getJson('/api/audit-logs?action=summary.requested')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject.label', null)
        ->assertDontSee('Secret');
    // Other entries are unaffected.
    $this->getJson('/api/audit-logs?action=member.role_changed')->assertOk()->assertJsonPath('data.0.subject.label', $this->admin->name);
});

it('filters by action, actor and date', function () {
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/projects', ['name' => 'By owner'])->assertCreated();
    Sanctum::actingAs($this->admin);
    $id = $this->postJson('/api/projects', ['name' => 'By admin'])->json('data.id');
    $this->patchJson("/api/projects/{$id}", ['name' => 'By admin 2'])->assertOk();
    DB::table('audit_logs')->where('subject_label', 'By owner')->update(['created_at' => '2026-01-15 23:59:00']);

    $labels = fn (string $query) => collect($this->getJson("/api/audit-logs?{$query}")->assertOk()->json('data'))->pluck('subject.label')->all();

    expect($labels('action=project.created'))->toBe(['By admin', 'By owner'])
        ->and($labels("actor_id={$this->owner->id}"))->toBe(['By owner'])
        ->and($labels('from=2026-01-15&to=2026-01-15'))->toBe(['By owner'])
        ->and($labels('to=2026-01-14'))->toBe([])
        ->and($labels("action=project.updated&actor_id={$this->admin->id}"))->toBe(['By admin 2']);
});

it('validates the filters', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/audit-logs?action=project.exploded&actor_id=abc&from=15-01-2026')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['action', 'actor_id', 'from']);
    $this->getJson('/api/audit-logs?from=2026-02-01&to=2026-01-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);
});

it('paginates', function () {
    inOrganization($this->acme, fn () => Project::factory()->count(30)->create());
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/audit-logs?page=2')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.total', 30);
});

it('lists the action catalogue', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/audit-log/actions')
        ->assertOk()
        ->assertJsonFragment(['value' => 'member.role_changed', 'group' => 'Members', 'label' => 'Changed a member\'s role']);
});
