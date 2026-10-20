<?php

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ], $overrides);
}

it('registers a user and returns a working token', function () {
    $response = $this->postJson('/api/register', validRegistration());

    $response->assertCreated()
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'current_organization', 'created_at'], 'token'])
        ->assertJsonPath('user.email', 'ada@example.com')
        ->assertJsonMissingPath('user.password');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect(Hash::check('secret-password', $user->password))->toBeTrue();

    $this->withToken($response->json('token'))
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('creates a personal organization owned by the new user and makes it current', function () {
    $response = $this->postJson('/api/register', validRegistration())->assertCreated();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $organization = $user->organizations()->sole();

    expect($organization->name)->toBe("Ada Lovelace's organization")
        ->and($organization->membership->role)->toBe(OrganizationRole::Owner)
        ->and($user->current_organization_id)->toBe($organization->id);

    $response->assertJsonPath('user.current_organization.id', $organization->id)
        ->assertJsonPath('user.current_organization.role', 'owner');
});

it('requires name, email and password', function () {
    $this->postJson('/api/register', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

it('rejects an invalid email', function () {
    $this->postJson('/api/register', validRegistration(['email' => 'not-an-email']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/register', validRegistration())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect(User::count())->toBe(1);
});

it('rejects a password that does not match its confirmation', function () {
    $this->postJson('/api/register', validRegistration(['password_confirmation' => 'something-else']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('rejects a password that is too short', function () {
    $this->postJson('/api/register', validRegistration(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});
