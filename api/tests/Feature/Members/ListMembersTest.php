<?php

use App\Enums\DefaultRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists the members of the current organization with their roles', function (DefaultRole $viewerRole) {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    $viewer = $viewerRole === DefaultRole::Owner ? $owner : addMember($acme, $viewerRole);
    $admin = addMember($acme, DefaultRole::Admin, ['name' => 'Zed Admin']);

    // Another organization's members must not appear.
    [$outsider] = userInOrganization(DefaultRole::Owner, 'Other Corp');

    Sanctum::actingAs($viewer);

    $response = $this->getJson('/api/members')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($owner->id, $admin->id, $viewer->id)
        ->not->toContain($outsider->id);

    $adminRow = collect($response->json('data'))->firstWhere('id', $admin->id);
    expect($adminRow['role']['name'])->toBe('Admin')
        ->and($adminRow)->toHaveKeys(['name', 'email', 'joined_at']);
})->with([DefaultRole::Owner, DefaultRole::Admin, DefaultRole::Member]);

it('requires a current organization', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/members')
        ->assertForbidden()
        ->assertJson(['message' => 'No active organization.']);
});

it('requires authentication', function () {
    $this->getJson('/api/members')->assertUnauthorized();
});
