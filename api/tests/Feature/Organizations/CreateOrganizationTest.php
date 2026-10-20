<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Laravel\Sanctum\Sanctum;

it('creates an organization owned by the user and switches to it', function () {
    [$user] = userInOrganization();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/organizations', ['name' => 'New Co'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Co')
        ->assertJsonPath('data.role', 'owner');

    $organization = Organization::findOrFail($response->json('data.id'));

    expect($user->fresh()->current_organization_id)->toBe($organization->id)
        ->and($organization->members()->sole()->is($user))->toBeTrue()
        ->and($organization->members()->sole()->membership->role)->toBe(OrganizationRole::Owner);
});

it('requires a name', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/organizations', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('rejects a name that is too long', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/organizations', ['name' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('rejects unauthenticated requests', function () {
    $this->postJson('/api/organizations', ['name' => 'New Co'])->assertUnauthorized();

    expect(Organization::count())->toBe(0);
});
