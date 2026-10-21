<?php

use App\Enums\PlatformAuditAction;
use App\Models\User;

it('makes a user a platform admin and logs it without an actor', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);

    $this->artisan('admin:grant', ['email' => 'ada@example.com'])
        ->expectsOutputToContain('is now a platform admin')
        ->assertSuccessful();

    expect($user->fresh()->is_platform_admin)->toBeTrue();
    $entry = platformAuditLog()->sole();
    expect($entry->action)->toBe(PlatformAuditAction::AdminGranted)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->ip_address)->toBeNull();
});

it('refuses someone without two-factor', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('admin:grant', ['email' => 'ada@example.com'])
        ->expectsOutputToContain('two-factor authentication turned on first')
        ->assertFailed();

    expect($user->fresh()->is_platform_admin)->toBeFalse()
        ->and(platformAuditLog())->toBeEmpty();
});

it('does nothing for someone who is already an admin', function () {
    User::factory()->platformAdmin()->withTwoFactor()->create(['email' => 'ada@example.com']);

    $this->artisan('admin:grant', ['email' => 'ada@example.com'])->expectsOutputToContain('already')->assertSuccessful();

    expect(platformAuditLog())->toBeEmpty();
});

it('removes a platform admin and logs it', function () {
    $user = User::factory()->platformAdmin()->create(['email' => 'ada@example.com']);

    $this->artisan('admin:revoke', ['email' => 'ada@example.com'])
        ->expectsOutputToContain('no longer a platform admin')
        ->assertSuccessful();

    expect($user->fresh()->is_platform_admin)->toBeFalse()
        ->and(platformAuditLog()->sole()->action)->toBe(PlatformAuditAction::AdminRevoked);
});

it('fails for an unknown email', function (string $command) {
    $this->artisan($command, ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user')
        ->assertFailed();

    expect(platformAuditLog())->toBeEmpty();
})->with(['admin:grant', 'admin:revoke']);

it('takes access away on the next request after a revoke', function () {
    [$admin, $token] = platformAdmin();
    $this->withToken($token)->getJson('/api/admin/users')->assertOk();

    $this->artisan('admin:revoke', ['email' => $admin->email])->assertSuccessful();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/users')->assertNotFound();
});
