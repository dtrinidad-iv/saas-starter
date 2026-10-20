<?php

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
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'created_at'], 'token'])
        ->assertJsonPath('user.email', 'ada@example.com')
        ->assertJsonMissingPath('user.password');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect(Hash::check('secret-password', $user->password))->toBeTrue();

    $this->withToken($response->json('token'))
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
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
