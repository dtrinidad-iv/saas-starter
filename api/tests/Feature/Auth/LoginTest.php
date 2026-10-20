<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'secret-password',
    ]);
});

it('logs in with valid credentials and returns a working token', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'current_organization', 'created_at'], 'token'])
        ->assertJsonPath('user.id', $this->user->id)
        ->assertJsonMissingPath('user.password');

    expect($this->user->tokens()->count())->toBe(1);

    $this->withToken($response->json('token'))
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com');
});

it('rejects a wrong password', function () {
    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonMissingPath('token');

    expect($this->user->tokens()->count())->toBe(0);
});

it('rejects an unknown email with the same error as a wrong password', function () {
    $wrongPassword = $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])->json('errors.email');

    $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email', $wrongPassword);
});

it('requires email and password', function () {
    $this->postJson('/api/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects an invalid email format', function () {
    $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'secret-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
    }

    $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});
