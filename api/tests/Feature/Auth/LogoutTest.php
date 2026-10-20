<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;
    $otherToken = $user->createToken('other-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/logout')
        ->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['other-device']);

    // The auth guard caches the user between requests in one test; reset it.
    Auth::forgetGuards();

    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    $this->withToken($otherToken)->getJson('/api/me')->assertOk();
});

it('rejects unauthenticated logout', function () {
    $this->postJson('/api/logout')->assertUnauthorized();
});
