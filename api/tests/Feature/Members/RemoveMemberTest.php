<?php

use App\Enums\DefaultRole;
use App\Models\Organization;
use Laravel\Sanctum\Sanctum;

it('applies the removal permissions', function (DefaultRole $actorRole, DefaultRole $targetRole, bool $allowed) {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner);
    $actor = $actorRole === DefaultRole::Owner ? $owner : addMember($acme, $actorRole);
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
    'owner removes member' => [DefaultRole::Owner, DefaultRole::Member, true],
    'owner removes admin' => [DefaultRole::Owner, DefaultRole::Admin, true],
    'owner removes other owner' => [DefaultRole::Owner, DefaultRole::Owner, true],
    'admin removes member' => [DefaultRole::Admin, DefaultRole::Member, true],
    'admin removes admin' => [DefaultRole::Admin, DefaultRole::Admin, false],
    'admin removes owner' => [DefaultRole::Admin, DefaultRole::Owner, false],
    'member removes member' => [DefaultRole::Member, DefaultRole::Member, false],
]);

it('lets anyone leave', function (DefaultRole $role) {
    [, $acme] = userInOrganization(DefaultRole::Owner);
    $member = addMember($acme, $role);
    Sanctum::actingAs($member);

    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    expect($member->isMemberOf($acme))->toBeFalse()
        ->and($member->fresh()->current_organization_id)->toBeNull();
})->with([DefaultRole::Member, DefaultRole::Admin, DefaultRole::Owner]);

it('refuses to let the last owner leave', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner);
    addMember($acme, DefaultRole::Admin);
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/members/{$owner->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['member']);

    expect($owner->isMemberOf($acme))->toBeTrue();
});

it('moves a removed member to another of their organizations', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    $member = addMember($acme);
    $beta = Organization::factory()->withMember($member)->create(['name' => 'Beta']);
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    expect($member->fresh()->current_organization_id)->toBe($beta->id);
});

it('cuts off access immediately for the removed member', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner);
    $member = addMember($acme);
    $token = $member->createToken('api')->plainTextToken;

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/members')->assertForbidden();
    $this->withToken($token)->getJson('/api/me')->assertJsonPath('data.current_organization', null);
});

it('cannot remove someone in another organization', function () {
    [$owner] = userInOrganization(DefaultRole::Owner, 'Acme');
    [$otherOwner, $otherOrganization] = userInOrganization(DefaultRole::Owner, 'Other Corp');
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/members/{$otherOwner->id}")->assertNotFound();

    expect($otherOwner->isMemberOf($otherOrganization))->toBeTrue();
});
