<?php

use App\Actions\Organizations\ChangeMemberRole;
use App\Enums\DefaultRole;
use App\Enums\ProjectSummaryStatus;
use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
    Sanctum::actingAs($this->owner);
});

it('records invitations without their token', function () {
    Mail::fake();
    $role = roleOf($this->acme, DefaultRole::Admin);

    $id = $this->postJson('/api/invitations', ['email' => 'New@Example.com', 'role_id' => $role->id])->assertCreated()->json('data.id');
    $this->deleteJson("/api/invitations/{$id}")->assertNoContent();

    [$invited, $revoked] = auditLogOf($this->acme)->all();

    expect($invited)
        ->action->value->toBe('member.invited')
        ->subject_type->toBe('invitation')
        ->subject_label->toBe('new@example.com')
        ->metadata->toBe(['role' => 'Admin'])
        ->and($revoked)
        ->action->value->toBe('invitation.revoked')
        ->subject_label->toBe('new@example.com')
        ->and(json_encode(auditLogOf($this->acme)->toArray()))->not->toContain('token');
});

it('records role changes with the old and new role', function () {
    $member = addMember($this->acme, DefaultRole::Member);

    $this->patchJson("/api/members/{$member->id}", ['role_id' => roleOf($this->acme, DefaultRole::Admin)->id])->assertOk();
    $this->patchJson("/api/members/{$member->id}", ['role_id' => roleOf($this->acme, DefaultRole::Admin)->id])->assertOk();

    expect(auditLogOf($this->acme)->sole())
        ->action->value->toBe('member.role_changed')
        ->actor_id->toBe($this->owner->id)
        ->subject_id->toBe($member->id)
        ->subject_label->toBe($member->name)
        ->changes->toEqual(['role' => ['old' => 'Member', 'new' => 'Admin']]);
});

it('records the role a member has at the time of the change', function () {
    $member = addMember($this->acme, DefaultRole::Member);
    $stale = $this->acme->members()->findOrFail($member->id);
    // Changed after $stale was loaded, e.g. by another request.
    $this->acme->members()->updateExistingPivot($member->id, ['role_id' => roleOf($this->acme, DefaultRole::Admin)->id]);

    app(ChangeMemberRole::class)->handle($this->acme, $stale, roleOf($this->acme, DefaultRole::Member));

    expect(auditLogOf($this->acme)->sole()->changes)->toEqual(['role' => ['old' => 'Admin', 'new' => 'Member']]);
});

it('records removing a member and leaving', function () {
    $member = addMember($this->acme, DefaultRole::Member);
    $leaver = addMember($this->acme, DefaultRole::Admin);

    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();
    Sanctum::actingAs($leaver);
    $this->deleteJson("/api/members/{$leaver->id}")->assertNoContent();

    [$removed, $left] = auditLogOf($this->acme)->all();

    expect($removed)
        ->action->value->toBe('member.removed')
        ->actor_id->toBe($this->owner->id)
        ->subject_id->toBe($member->id)
        ->metadata->toBe(['role' => 'Member'])
        ->and($left)
        ->actor_id->toBe($leaver->id)
        ->subject_id->toBe($leaver->id);
});

it('records joining with the invited role', function () {
    $joiner = addMember($this->acme); // placeholder org membership for a current organization
    $this->acme->members()->detach($joiner->id);
    [, $token] = createInvitation($this->acme, $joiner->email, DefaultRole::Admin);
    Sanctum::actingAs($joiner);

    $this->postJson("/api/join/{$token}")->assertOk();

    expect(auditLogOf($this->acme)->sole())
        ->action->value->toBe('member.joined')
        ->actor_id->toBe($joiner->id)
        ->subject_id->toBe($joiner->id)
        ->metadata->role->toBe('Admin');
});

it('records summary requests and reviews', function () {
    $project = inOrganization($this->acme, fn () => Project::withoutAuditing(fn () => Project::factory()->create(['name' => 'Launch'])));
    $draft = createSummary($this->acme, $project, ProjectSummaryStatus::Draft);
    $this->ai->push(['summary' => 'New.', 'key_points' => []]);

    $this->postJson("/api/projects/{$project->id}/summary")->assertSuccessful();
    $second = $project->summaries()->latest('id')->first();
    $this->postJson("/api/projects/{$project->id}/summaries/{$second->id}/approve")->assertOk();

    expect(auditLogOf($this->acme)->map(fn (AuditLog $entry) => [$entry->action->value, $entry->subject_label, $entry->actor_id])->all())
        ->toBe([
            ['summary.requested', 'Launch', $this->owner->id],
            ['summary.approved', 'Launch', $this->owner->id],
        ])
        ->and($draft->fresh()->status)->toBe(ProjectSummaryStatus::Rejected);
});

it('records automatic summary requests as the project creator\'s', function () {
    $this->ai->push(['summary' => 'Auto.', 'key_points' => []]);

    $this->postJson('/api/projects', ['name' => 'Auto', 'description' => 'Something'])->assertCreated();

    expect(auditLogOf($this->acme)->map(fn (AuditLog $entry) => [$entry->action->value, $entry->actor_id])->all())
        ->toBe([['project.created', $this->owner->id], ['summary.requested', $this->owner->id]]);
});
