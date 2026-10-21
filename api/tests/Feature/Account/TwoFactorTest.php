<?php

use App\Models\User;
use App\Notifications\RecoveryCodesRegenerated;
use App\Notifications\TwoFactorDisabled;
use App\Notifications\TwoFactorEnabled;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function currentCode(string $secret): string
{
    return Totp::codeAt($secret, Totp::step(now()->getTimestamp()));
}

it('needs a logged-in user for every two-factor endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['post', '/api/account/two-factor'],
    ['post', '/api/account/two-factor/confirm'],
    ['post', '/api/account/two-factor/recovery-codes'],
    ['delete', '/api/account/two-factor'],
]);

it('needs the current password to start the setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor', ['current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('starts the setup with a secret and an otpauth link, still off', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor', ['current_password' => 'password'])
        ->assertOk();

    $secret = $response->json('data.secret');
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($response->json('data.otpauth_uri'))->toStartWith('otpauth://totp/')->toContain('secret='.$secret)->toContain('ada%40example.com')
        ->and($user->fresh()->two_factor_secret)->toBe($secret)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    // Encrypted at rest.
    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->not->toContain($secret);

    $this->actingAs($user, 'sanctum')->getJson('/api/me')->assertJsonPath('data.two_factor_enabled', false);
});

it('turns two-factor on with a right code, returns recovery codes and signs out other devices', function () {
    $user = User::factory()->create();
    $user->createToken('phone');
    $current = $user->createToken('laptop');

    $secret = $this->withToken($current->plainTextToken)
        ->postJson('/api/account/two-factor', ['current_password' => 'password'])
        ->json('data.secret');

    $codes = $this->withToken($current->plainTextToken)
        ->postJson('/api/account/two-factor/confirm', ['code' => currentCode($secret)])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($codes[0])->toMatch('/^[a-z0-9]{5}-[a-z0-9]{5}$/')
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toBe(RecoveryCodes::hashAll($codes))
        ->and($user->tokens()->pluck('id')->all())->toBe([$current->accessToken->id]);

    Notification::assertSentTo($user, TwoFactorEnabled::class);

    $this->app['auth']->forgetGuards();
    $this->withToken($current->plainTextToken)->getJson('/api/me')->assertOk()->assertJsonPath('data.two_factor_enabled', true);
});

it('leaves two-factor off after a wrong code', function () {
    $user = User::factory()->create();

    $secret = $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor', ['current_password' => 'password'])
        ->json('data.secret');
    $wrong = currentCode($secret) === '000000' ? '111111' : '000000';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor/confirm', ['code' => $wrong])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    Notification::assertNothingSent();
});

it('refuses to confirm without a started setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor/confirm', ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('refuses to start again while two-factor is on, keeping the secret', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor', ['current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['two_factor']);

    expect($user->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
});

it('turns two-factor off with the password and emails the account', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account/two-factor', ['current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account/two-factor', ['current_password' => 'password'])
        ->assertNoContent();

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
    Notification::assertSentTo($user, TwoFactorDisabled::class);
});

it('makes new recovery codes with the password, and the old ones stop working', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'wrong'])
        ->assertUnprocessable();

    $codes = $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password'])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($user->fresh()->two_factor_recovery_codes)->toBe(RecoveryCodes::hashAll($codes))
        ->and($user->fresh()->two_factor_recovery_codes)->not->toContain(RecoveryCodes::hash('aaaaa-bbbbb'));
    Notification::assertSentTo($user, RecoveryCodesRegenerated::class);
});

it('refuses new recovery codes while two-factor is off', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['two_factor']);
});

it('never shows the secret or recovery codes in the user', function () {
    $user = User::factory()->withTwoFactor()->create();

    $json = $this->actingAs($user, 'sanctum')->getJson('/api/me')->assertOk()->getContent();

    expect($json)->not->toContain('two_factor_secret')
        ->not->toContain('JBSWY3DPEHPK3PXP')
        ->not->toContain('recovery')
        ->and($user->fresh()->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_used_step']);
});

it('only changes the logged-in user', function () {
    $user = User::factory()->create();
    $other = User::factory()->withTwoFactor()->create();
    $other->createToken('phone');
    $otherCodes = $other->two_factor_recovery_codes;

    $secret = $this->actingAs($user, 'sanctum')->postJson('/api/account/two-factor', ['current_password' => 'password'])->assertOk()->json('data.secret');
    $this->actingAs($user, 'sanctum')->postJson('/api/account/two-factor/confirm', ['code' => currentCode($secret)])->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password'])->assertOk();
    $this->actingAs($user, 'sanctum')->deleteJson('/api/account/two-factor', ['current_password' => 'password'])->assertNoContent();

    $fresh = $other->fresh();
    expect($fresh->hasTwoFactorEnabled())->toBeTrue()
        ->and($fresh->two_factor_secret)->toBe('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP')
        ->and($fresh->two_factor_recovery_codes)->toBe($otherCodes)
        ->and($other->tokens()->count())->toBe(1);
    Notification::assertNothingSentTo($other);
});
