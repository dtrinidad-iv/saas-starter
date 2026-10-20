<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Laravel\Sanctum\Sanctum;

it('switches to another organization the user belongs to', function () {
    [$user] = userInOrganization();
    $beta = Organization::factory()->withMember($user, OrganizationRole::Admin)->create(['name' => 'Beta']);
    Sanctum::actingAs($user);

    $this->postJson("/api/organizations/{$beta->id}/switch")
        ->assertOk()
        ->assertJsonPath('data.id', $beta->id)
        ->assertJsonPath('data.role', 'admin');

    expect($user->fresh()->current_organization_id)->toBe($beta->id);

    $this->getJson('/api/me')->assertJsonPath('data.current_organization.id', $beta->id);
});

it('refuses to switch to an organization the user does not belong to', function () {
    [$user, $acme] = userInOrganization();
    [, $otherOrganization] = userInOrganization(name: 'Other Corp');
    Sanctum::actingAs($user);

    $this->postJson("/api/organizations/{$otherOrganization->id}/switch")
        ->assertNotFound();

    expect($user->fresh()->current_organization_id)->toBe($acme->id);
});

it('answers a missing organization the same way as a foreign one', function () {
    [$user, $acme] = userInOrganization();
    Sanctum::actingAs($user);

    $this->postJson('/api/organizations/999999/switch')->assertNotFound();

    expect($user->fresh()->current_organization_id)->toBe($acme->id);
});

it('refuses to switch back after the user was removed from the organization', function () {
    [$user, $acme] = userInOrganization();
    $beta = Organization::factory()->withMember($user)->create();
    $beta->members()->detach($user);
    Sanctum::actingAs($user);

    $this->postJson("/api/organizations/{$beta->id}/switch")->assertNotFound();

    expect($user->fresh()->current_organization_id)->toBe($acme->id);
});

it('rejects unauthenticated requests', function () {
    [, $acme] = userInOrganization();

    $this->postJson("/api/organizations/{$acme->id}/switch")->assertUnauthorized();
});
