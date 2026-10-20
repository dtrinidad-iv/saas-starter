<?php

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
                'created_at' => $user->created_at->toJSON(),
            ],
        ]);
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
