<?php

use App\Enums\PlatformAuditAction;
use App\Models\User;
use App\Notifications\TwoFactorLockedOut;
use App\Support\Totp;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;

const ADMIN_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

beforeEach(function () {
    Notification::fake();
    $this->freezeTime();
});

function adminCode(int $offset = 0): string
{
    return Totp::codeAt(ADMIN_SECRET, Totp::step(now()->getTimestamp()) + $offset);
}

function wrongAdminCode(): string
{
    return adminCode() === '000000' ? '111111' : '000000';
}

dataset('admin routes', [
    'users' => fn () => '/api/admin/users',
    'user' => fn () => '/api/admin/users/'.User::factory()->create()->id,
    'organizations' => fn () => '/api/admin/organizations',
    'organization' => fn () => '/api/admin/organizations/'.userInOrganization()[1]->id,
    'audit log' => fn () => '/api/admin/audit-log',
]);

it('needs a logged-in user', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized();
})->with('admin routes');

it('answers 404 to anyone who is not a platform admin, even with two-factor', function (string $uri) {
    config(['app.debug' => false]); // the production body, without a trace
    $user = User::factory()->withTwoFactor()->create();

    $this->withToken(adminToken($user, now()))->getJson($uri)
        ->assertNotFound()
        ->assertExactJson(['message' => 'The route '.ltrim(parse_url($uri, PHP_URL_PATH), '/').' could not be found.']);
})->with('admin routes');

it('gives a non-admin the same 404 for admin pages, missing ids and unknown routes', function () {
    config(['app.debug' => false]);
    $token = adminToken(User::factory()->withTwoFactor()->create(), now());
    $existing = User::factory()->create()->id;

    foreach (['api/admin/nope', 'api/admin/users', "api/admin/users/{$existing}", 'api/admin/users/999999', 'api/admin/organizations/999999'] as $path) {
        $this->withToken($token)->getJson('/'.$path)
            ->assertNotFound()
            ->assertExactJson(['message' => "The route {$path} could not be found."]);
    }
});

it('never throttles a non-admin, so a 429 cannot show the area exists', function () {
    $token = adminToken(User::factory()->withTwoFactor()->create(), now());

    foreach (range(1, 61) as $ignored) {
        $this->withToken($token)->getJson('/api/admin/users')->assertNotFound();
    }
});

it('throttles an admin at 60 requests a minute', function () {
    [, $token] = platformAdmin();

    foreach (range(1, 60) as $ignored) {
        $this->withToken($token)->getJson('/api/admin/audit-log')->assertOk();
    }

    $this->withToken($token)->getJson('/api/admin/audit-log')->assertTooManyRequests();
});

it('asks an admin without two-factor to turn it on', function (string $uri) {
    $admin = User::factory()->platformAdmin()->create();

    $this->withToken(adminToken($admin, now()))->getJson($uri)
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');
})->with('admin routes');

it('asks for a code when the token never passed one', function (string $uri) {
    [, $token] = platformAdmin(passedCode: false);

    $this->withToken($token)->getJson($uri)
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_reauth');
})->with('admin routes');

it('asks for a code again once the last one is older than the limit', function (string $uri) {
    config(['admin.two_factor_ttl_minutes' => 60]);
    [, $token] = platformAdmin(now()->subMinutes(61));

    $this->withToken($token)->getJson($uri)
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_reauth');
})->with('admin routes');

it('lets in an admin whose token passed a code recently', function (string $uri) {
    config(['admin.two_factor_ttl_minutes' => 60]);
    [, $token] = platformAdmin(now()->subMinutes(59));

    $this->withToken($token)->getJson($uri)->assertOk();
})->with('admin routes');

it('refuses an admin with an unverified email', function () {
    [, $token] = platformAdmin(attributes: ['email_verified_at' => null]);

    $this->withToken($token)->getJson('/api/admin/users')
        ->assertForbidden()
        ->assertJsonPath('code', 'email_unverified');
});

it('stops being an admin when two-factor is turned off, so a password alone cannot swap in a new authenticator', function () {
    [$admin, $token] = platformAdmin(attributes: ['password' => 'secret-password']);
    $this->withToken($token)->getJson('/api/admin/users')->assertOk();

    $this->withToken($token)->deleteJson('/api/account/two-factor', ['current_password' => 'secret-password'])->assertNoContent();

    expect($admin->fresh()->is_platform_admin)->toBeFalse();
    $entry = platformAuditLog()->firstWhere('action', PlatformAuditAction::AdminRevoked);
    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($admin->id)
        ->and($entry->metadata)->toBe(['reason' => 'two_factor_disabled']);

    // Setting it up again on the same token doesn't bring admin back.
    $secret = $this->withToken($token)->postJson('/api/account/two-factor', ['current_password' => 'secret-password'])->json('data.secret');
    $this->withToken($token)->postJson('/api/account/two-factor/confirm', [
        'code' => Totp::codeAt($secret, Totp::step(now()->getTimestamp())),
    ])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/users')->assertNotFound();
});

it('leaves a non-admin who turns two-factor off out of the platform log', function () {
    $user = User::factory()->withTwoFactor()->create(['password' => 'secret-password']);

    $this->withToken(adminToken($user, null))->deleteJson('/api/account/two-factor', ['current_password' => 'secret-password'])->assertNoContent();

    expect(platformAuditLog())->toBeEmpty();
});

it('never lets the API set the platform admin flag', function () {
    $user = User::factory()->create();

    $this->withToken(adminToken($user, null))
        ->patchJson('/api/account', ['name' => 'Ada', 'is_platform_admin' => true])
        ->assertOk()
        ->assertJsonPath('data.is_platform_admin', false);

    $this->postJson('/api/register', [
        'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret-password',
        'password_confirmation' => 'secret-password', 'is_platform_admin' => true,
    ])->assertSuccessful();

    expect($user->fresh()->is_platform_admin)->toBeFalse()
        ->and(User::where('email', 'eve@example.com')->first()->is_platform_admin)->toBeFalse();
});

it('shows the flag in /me for the navigation', function () {
    [, $token] = platformAdmin();

    $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.is_platform_admin', true);
});

it('does not mark a token from a recovery-code login (a password alone can make new codes)', function () {
    User::factory()->withTwoFactor(ADMIN_SECRET, ['aaaaa-bbbbb'])->create(['email' => 'ada@example.com', 'password' => 'secret-password']);

    $challenge = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])->json('challenge');
    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'recovery_code' => 'aaaaa-bbbbb'])->assertOk();

    expect(User::where('email', 'ada@example.com')->first()->tokens()->sole()->two_factor_at)->toBeNull();
});

it('marks a token issued through the two-factor challenge, and not a password-only one', function () {
    User::factory()->withTwoFactor(ADMIN_SECRET)->create(['email' => 'ada@example.com', 'password' => 'secret-password']);
    User::factory()->create(['email' => 'bob@example.com', 'password' => 'secret-password']);

    $challenge = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])->json('challenge');
    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => adminCode()])->assertOk();
    $this->postJson('/api/login', ['email' => 'bob@example.com', 'password' => 'secret-password'])->assertOk();

    expect(User::where('email', 'ada@example.com')->first()->tokens()->sole()->two_factor_at)->not->toBeNull()
        ->and(User::where('email', 'bob@example.com')->first()->tokens()->sole()->two_factor_at)->toBeNull();
});

it('does not mark the token that confirmed a new two-factor setup (the factor is brand new)', function () {
    $admin = User::factory()->platformAdmin()->create(['password' => 'secret-password']);
    $token = adminToken($admin, null);

    $secret = $this->withToken($token)->postJson('/api/account/two-factor', ['current_password' => 'secret-password'])->json('data.secret');
    $this->withToken($token)->postJson('/api/account/two-factor/confirm', [
        'code' => Totp::codeAt($secret, Totp::step(now()->getTimestamp())),
    ])->assertOk();

    expect($admin->tokens()->sole()->two_factor_at)->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/users')
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_reauth');
});

describe('reconfirming with a code', function () {
    it('marks only the current token', function () {
        [$admin, $token] = platformAdmin(passedCode: false);
        adminToken($admin, null);

        $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => adminCode()])->assertNoContent();

        expect($admin->tokens()->whereNotNull('two_factor_at')->count())->toBe(1);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/users')->assertOk();
    });

    it('refuses a code that was already used', function () {
        [, $token] = platformAdmin(passedCode: false);
        $code = adminCode();

        $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => $code])->assertNoContent();
        $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    });

    it('counts wrong codes toward the account limit shared with login', function () {
        [$admin, $token] = platformAdmin(passedCode: false);
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (range(1, 10) as $ignored) {
            $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => wrongAdminCode()])->assertUnprocessable();
        }

        $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => adminCode()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Too many wrong codes']);
        Notification::assertSentToTimes($admin, TwoFactorLockedOut::class, 1);
        expect($admin->tokens()->sole()->two_factor_at)->toBeNull();
    });

    it('is throttled', function () {
        [, $token] = platformAdmin(passedCode: false);

        foreach (range(1, 6) as $ignored) {
            $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => wrongAdminCode()]);
        }

        $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => adminCode()])->assertTooManyRequests();
    });

    it('does nothing for a user without two-factor', function () {
        $user = User::factory()->create();

        $this->withToken(adminToken($user, null))->postJson('/api/account/two-factor/reconfirm', ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['two_factor']);

        expect($user->tokens()->sole()->two_factor_at)->toBeNull();
    });
});
