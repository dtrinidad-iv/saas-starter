<?php

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

/**
 * The API path (with signature) that the Nuxt page posts, from the link in the email.
 */
function verificationPath(User $user): string
{
    $path = null;

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$path) {
        $url = $notification->toMail($user)->actionUrl;
        expect($url)->toStartWith(config('app.frontend_url').'/verify-email?');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = "/api/email/verify/{$query['id']}/{$query['hash']}?".http_build_query(['expires' => $query['expires'], 'signature' => $query['signature']]);

        return true;
    });

    return $path;
}

it('sends a verification email on registration and returns an unverified user', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated()->assertJsonPath('user.email_verified', false);

    Notification::assertSentTo(User::where('email', 'ada@example.com')->sole(), VerifyEmail::class);
    expect($response->json('token'))->toBeString();
});

it('verifies the email with the link', function () {
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    $this->actingAs($user, 'sanctum')
        ->postJson(verificationPath($user))
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('accepts the link again once verified', function () {
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();
    $path = verificationPath($user);

    $this->actingAs($user, 'sanctum')->postJson($path)->assertOk();
    $this->actingAs($user, 'sanctum')->postJson($path)->assertOk()->assertJsonPath('data.email_verified', true);
});

it("refuses another user's link", function () {
    $owner = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $owner->sendEmailVerificationNotification();

    $this->actingAs($other, 'sanctum')->postJson(verificationPath($owner))->assertForbidden();

    expect($owner->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a link for an address the user no longer has', function () {
    $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
    $user->sendEmailVerificationNotification();
    $path = verificationPath($user);

    $user->forceFill(['email' => 'new@example.com'])->save();

    $this->actingAs($user, 'sanctum')->postJson($path)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a tampered or expired link', function () {
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();
    $path = verificationPath($user);

    $this->actingAs($user, 'sanctum')->postJson(preg_replace('/signature=[^&]+/', 'signature=bad', $path))->assertForbidden();
    $this->actingAs($user, 'sanctum')->postJson(strtok($path, '?'))->assertForbidden();

    $this->travel(61)->minutes();
    $this->actingAs($user, 'sanctum')->postJson($path)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('needs a logged-in user', function () {
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    $this->postJson(verificationPath($user))->assertUnauthorized();
});

it('resends the link to an unverified user only', function () {
    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create();

    $this->actingAs($unverified, 'sanctum')->postJson('/api/email/verification-notification')
        ->assertAccepted()->assertJsonPath('email_verified', false);
    $this->actingAs($verified, 'sanctum')->postJson('/api/email/verification-notification')
        ->assertOk()->assertJsonPath('email_verified', true)->assertJsonPath('message', 'Your email is already verified.');

    Notification::assertSentToTimes($unverified, VerifyEmail::class, 1);
    Notification::assertNotSentTo($verified, VerifyEmail::class);
});

it('throttles resending', function () {
    $user = User::factory()->unverified()->create();

    foreach (range(1, 6) as $_) {
        $this->actingAs($user, 'sanctum')->postJson('/api/email/verification-notification')->assertAccepted();
    }

    $this->actingAs($user, 'sanctum')->postJson('/api/email/verification-notification')->assertTooManyRequests();
});
