<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Laravel\Sanctum\Sanctum;

it('applies the removal permissions', function (OrganizationRole $actorRole, OrganizationRole $targetRole, bool $allowed) {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner);
    $actor = $actorRole === OrganizationRole::Owner ? $owner : addMember($acme, $actorRole);
    $target = addMember($acme, $targetRole);

    Sanctum::actingAs($actor);

    $response = $this->deleteJson("/api/members/{$target->id}");

    if ($allowed) {
        $response->assertNoContent();
        expect($target->isMemberOf($acme))->toBeFalse();
    } else {
        $response->assertForbidden();
        expect($target->isMemberOf($acme))->toBeTrue();
    }
})->with([
    'owner removes member' => [OrganizationRole::Owner, OrganizationRole::Member, true],
    'owner removes admin' => [OrganizationRole::Owner, OrganizationRole::Admin, true],
    'owner removes other owner' => [OrganizationRole::Owner, OrganizationRole::Owner, true],
    'admin removes member' => [OrganizationRole::Admin, OrganizationRole::Member, true],
    'admin removes admin' => [OrganizationRole::Admin, OrganizationRole::Admin, false],
    'admin removes owner' => [OrganizationRole::Admin, OrganizationRole::Owner, false],
    'member removes member' => [OrganizationRole::Member, OrganizationRole::Member, false],
]);

it('lets anyone leave', function (OrganizationRole $role) {
    [, $acme] = userInOrganization(OrganizationRole::Owner);
    $member = addMember($acme, $role);
    Sanctum::actingAs($member);

    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    expect($member->isMemberOf($acme))->toBeFalse()
        ->and($member->fresh()->current_organization_id)->toBeNull();
})->with([OrganizationRole::Member, OrganizationRole::Admin, OrganizationRole::Owner]);

it('refuses to let the last owner leave', function () {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner);
    addMember($acme, OrganizationRole::Admin);
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/members/{$owner->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['member']);

    expect($owner->isMemberOf($acme))->toBeTrue();
});

it('moves a removed member to another of their organizations', function () {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner, 'Acme');
    $member = addMember($acme);
    $beta = Organization::factory()->withMember($member)->create(['name' => 'Beta']);
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    expect($member->fresh()->current_organization_id)->toBe($beta->id);
});

it('cuts off access immediately for the removed member', function () {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner);
    $member = addMember($acme);
    $token = $member->createToken('api')->plainTextToken;

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/members')->assertForbidden();
    $this->withToken($token)->getJson('/api/me')->assertJsonPath('data.current_organization', null);
});

it('cannot remove someone in another organization', function () {
    [$owner] = userInOrganization(OrganizationRole::Owner, 'Acme');
    [$otherOwner, $otherOrganization] = userInOrganization(OrganizationRole::Owner, 'Other Corp');
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/members/{$otherOwner->id}")->assertNotFound();

    expect($otherOwner->isMemberOf($otherOrganization))->toBeTrue();
});
