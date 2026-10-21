<?php

use App\Models\User;
use App\Notifications\EmailChanged;
use App\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('updates the name', function () {
    $user = User::factory()->create(['name' => 'Ada']);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/account', ['name' => 'Ada Lovelace'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada Lovelace');

    expect($user->fresh()->name)->toBe('Ada Lovelace');
});

it('validates the name', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/account', ['name' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('needs a logged-in user for every account endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['patch', '/api/account'],
    ['put', '/api/account/email'],
    ['put', '/api/account/password'],
    ['delete', '/api/account'],
    ['get', '/api/account/devices'],
    ['delete', '/api/account/devices/1'],
]);

it('changes the email, unverifies it, sends a link to the new address and a notice to the old one', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.email_verified', false);

    expect($user->fresh()->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
    Notification::assertSentOnDemand(EmailChanged::class, function (EmailChanged $notification, array $channels, AnonymousNotifiable $notifiable) {
        $mail = $notification->toMail($notifiable)->render();

        return $notifiable->routes['mail'] === 'old@example.com'
            && str_contains((string) $mail, 'n***@example.com')
            && ! str_contains((string) $mail, 'new@example.com');
    });
});

it('signs out other devices when the email changes, keeping this one', function () {
    $user = User::factory()->create();
    $here = $user->createToken('here')->plainTextToken;
    $user->createToken('elsewhere');

    $this->withToken($here)
        ->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'password'])
        ->assertOk();

    expect($user->tokens()->pluck('name')->all())->toBe(['here']);
});

it('does nothing when the email is the same', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'ada@example.com', 'current_password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    Notification::assertNothingSent();
});

it('needs the current password to change the email', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->email)->toBe('old@example.com');
    Notification::assertNothingSent();
});

it('rejects an email another account uses, or one that is not lowercase', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'taken@example.com', 'current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'New@Example.com', 'current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('changes the password and signs out other devices, keeping this one', function () {
    $user = User::factory()->create();
    $here = $user->createToken('here')->plainTextToken;
    $elsewhere = $user->createToken('elsewhere')->plainTextToken;

    $this->withToken($here)
        ->putJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])
        ->assertNoContent();

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->pluck('name')->all())->toBe(['here']);

    $this->app['auth']->forgetGuards();
    $this->withToken($here)->getJson('/api/me')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($elsewhere)->getJson('/api/me')->assertUnauthorized();
});

it('needs the current password and a valid new one', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/password', ['current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'other'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password', 'password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});
