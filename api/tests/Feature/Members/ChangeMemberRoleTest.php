<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function roleIn(Organization $organization, User $user): ?OrganizationRole
{
    return $organization->members()->find($user->id)?->membership->role;
}

it('applies the role change permissions', function (OrganizationRole $actorRole, OrganizationRole $targetRole, OrganizationRole $newRole, bool $allowed) {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner);
    $actor = $actorRole === OrganizationRole::Owner ? $owner : addMember($acme, $actorRole);
    $target = addMember($acme, $targetRole);

    Sanctum::actingAs($actor);

    $response = $this->patchJson("/api/members/{$target->id}", ['role' => $newRole->value]);

    if ($allowed) {
        $response->assertOk()->assertJsonPath('data.role', $newRole->value);
        expect(roleIn($acme, $target))->toBe($newRole);
    } else {
        $response->assertForbidden();
        expect(roleIn($acme, $target))->toBe($targetRole);
    }
})->with([
    'owner: member -> admin' => [OrganizationRole::Owner, OrganizationRole::Member, OrganizationRole::Admin, true],
    'owner: admin -> owner' => [OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Owner, true],
    'owner: other owner -> member' => [OrganizationRole::Owner, OrganizationRole::Owner, OrganizationRole::Member, true],
    'admin: member -> admin' => [OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Admin, true],
    'admin: other admin -> member' => [OrganizationRole::Admin, OrganizationRole::Admin, OrganizationRole::Member, true],
    'admin: member -> owner' => [OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Owner, false],
    'admin: owner -> member' => [OrganizationRole::Admin, OrganizationRole::Owner, OrganizationRole::Member, false],
    'member: member -> admin' => [OrganizationRole::Member, OrganizationRole::Member, OrganizationRole::Admin, false],
]);

it('refuses to demote the last owner', function () {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$owner->id}", ['role' => 'admin'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);

    expect(roleIn($acme, $owner))->toBe(OrganizationRole::Owner);
});

it('lets an owner step down when another owner remains', function () {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner);
    addMember($acme, OrganizationRole::Owner);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$owner->id}", ['role' => 'admin'])->assertOk();

    expect(roleIn($acme, $owner))->toBe(OrganizationRole::Admin);

    // Their permissions follow immediately.
    $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role' => 'owner'])->assertForbidden();
});

it('validates the role', function () {
    [$owner, $acme] = userInOrganization();
    $member = addMember($acme);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$member->id}", ['role' => 'superuser'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('cannot change the role of someone in another organization', function () {
    [$owner] = userInOrganization(OrganizationRole::Owner, 'Acme');
    [, $otherOrganization] = userInOrganization(OrganizationRole::Owner, 'Other Corp');
    $outsider = addMember($otherOrganization, OrganizationRole::Member);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/members/{$outsider->id}", ['role' => 'admin'])->assertNotFound();

    expect(roleIn($otherOrganization, $outsider))->toBe(OrganizationRole::Member);
});
