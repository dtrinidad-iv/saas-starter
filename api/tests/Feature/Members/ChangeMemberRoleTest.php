<?php

use App\Enums\DefaultRole;
use Laravel\Sanctum\Sanctum;

it('applies the role change permissions', function (DefaultRole $actorRole, DefaultRole $targetRole, DefaultRole $newRole, bool $allowed) {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner);
    $actor = $actorRole === DefaultRole::Owner ? $owner : addMember($acme, $actorRole);
    $target = addMember($acme, $targetRole);

    Sanctum::actingAs($actor);

    $response = $this->patchJson("/api/members/{$target->id}", ['role_id' => roleOf($acme, $newRole)->id]);

    if ($allowed) {
        $response->assertOk()->assertJsonPath('data.role.name', $newRole->value);
        expect(roleIn($acme, $target)->name)->toBe($newRole->value);
    } else {
        $response->assertForbidden();
        expect(roleIn($acme, $target)->name)->toBe($targetRole->value);
    }
})->with([
    'owner: member -> admin' => [DefaultRole::Owner, DefaultRole::Member, DefaultRole::Admin, true],
    'owner: admin -> owner' => [DefaultRole::Owner, DefaultRole::Admin, DefaultRole::Owner, true],
    'owner: other owner -> member' => [DefaultRole::Owner, DefaultRole::Owner, DefaultRole::Member, true],
    'admin: member -> admin' => [DefaultRole::Admin, DefaultRole::Member, DefaultRole::Admin, true],
    'admin: other admin -> member' => [DefaultRole::Admin, DefaultRole::Admin, DefaultRole::Member, true],
    'admin: member -> owner' => [DefaultRole::Admin, DefaultRole::Member, DefaultRole::Owner, false],
    'admin: owner -> member' => [DefaultRole::Admin, DefaultRole::Owner, DefaultRole::Member, false],
    'member: member -> admin' => [DefaultRole::Member, DefaultRole::Member, DefaultRole::Admin, false],
]);

it('refuses to demote the last owner', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$owner->id}", ['role_id' => roleOf($acme, DefaultRole::Admin)->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);

    expect(roleIn($acme, $owner)->name)->toBe('Owner');
});

it('lets an owner step down when another owner remains', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner);
    addMember($acme, DefaultRole::Owner);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$owner->id}", ['role_id' => roleOf($acme, DefaultRole::Admin)->id])->assertOk();

    expect(roleIn($acme, $owner)->name)->toBe('Admin');

    // Their permissions follow immediately.
    $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => roleOf($acme, DefaultRole::Owner)->id])->assertForbidden();
});

it('validates the role', function () {
    [$owner, $acme] = userInOrganization();
    $member = addMember($acme);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$member->id}", ['role_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);
});

it('cannot change the role of someone in another organization', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    [, $otherOrganization] = userInOrganization(DefaultRole::Owner, 'Other Corp');
    $outsider = addMember($otherOrganization, DefaultRole::Member);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$outsider->id}", ['role_id' => roleOf($acme, DefaultRole::Admin)->id])->assertNotFound();

    expect(roleIn($otherOrganization, $outsider)->name)->toBe('Member');
});

it('rejects a role from another organization', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    [, $otherOrganization] = userInOrganization(DefaultRole::Owner, 'Other Corp');
    $member = addMember($acme);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$member->id}", ['role_id' => roleOf($otherOrganization, DefaultRole::Admin)->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);

    expect(roleIn($acme, $member)->name)->toBe('Member');
});
