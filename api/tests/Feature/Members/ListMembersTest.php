<?php

use App\Enums\OrganizationRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists the members of the current organization with their roles', function (OrganizationRole $viewerRole) {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner, 'Acme');
    $viewer = $viewerRole === OrganizationRole::Owner ? $owner : addMember($acme, $viewerRole);
    $admin = addMember($acme, OrganizationRole::Admin, ['name' => 'Zed Admin']);

    // Another organization's members must not appear.
    [$outsider] = userInOrganization(OrganizationRole::Owner, 'Other Corp');

    Sanctum::actingAs($viewer);

    $response = $this->getJson('/api/members')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($owner->id, $admin->id, $viewer->id)
        ->not->toContain($outsider->id);

    $adminRow = collect($response->json('data'))->firstWhere('id', $admin->id);
    expect($adminRow['role'])->toBe('admin')
        ->and($adminRow)->toHaveKeys(['name', 'email', 'joined_at']);
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member]);

it('requires a current organization', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/members')
        ->assertForbidden()
        ->assertJson(['message' => 'No active organization.']);
});

it('requires authentication', function () {
    $this->getJson('/api/members')->assertUnauthorized();
});
