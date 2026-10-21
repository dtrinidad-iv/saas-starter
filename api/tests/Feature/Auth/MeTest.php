<?php

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\User;

it('returns the authenticated user', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/me')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => true,
                'two_factor_enabled' => false,
                'is_platform_admin' => false,
                'impersonated_by' => null,
                'current_organization' => null,
                'created_at' => $user->created_at->toJSON(),
            ],
        ]);
});

it('includes the current organization and the user role in it', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user, DefaultRole::Admin)->create(['name' => 'Acme']);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    $this->withToken($user->createToken('api')->plainTextToken)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.current_organization.id', $organization->id)
        ->assertJsonPath('data.current_organization.name', 'Acme')
        ->assertJsonPath('data.current_organization.role.name', 'Admin');
});

it('reports no current organization after the user was removed from it', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user)->create();
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    $organization->members()->detach($user);

    $this->withToken($user->createToken('api')->plainTextToken)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.current_organization', null);
});

it('rejects requests without a token', function () {
    $this->getJson('/api/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('returns a JSON 401 even without an Accept: application/json header', function () {
    $this->get('/api/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('rejects requests with an invalid token', function () {
    $this->withToken('1|not-a-real-token')
        ->getJson('/api/me')
        ->assertUnauthorized();
});
