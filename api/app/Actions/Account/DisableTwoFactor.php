<?php

namespace App\Actions\Account;

use App\Actions\Admin\RecordPlatformAudit;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use App\Notifications\TwoFactorDisabled;
use Illuminate\Support\Facades\DB;

class DisableTwoFactor
{
    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * Turn two-factor off (the request checked the password), or drop a setup
     * that wasn't finished. The account gets an email when it was on.
     * A platform admin also stops being one: otherwise a password alone could swap
     * in a new authenticator. Only `admin:grant` makes them one again.
     */
    public function handle(User $user): void
    {
        $wasEnabled = DB::transaction(function () use ($user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $wasEnabled = $locked->hasTwoFactorEnabled();

            if ($locked->isPlatformAdmin()) {
                $locked->forceFill(['is_platform_admin' => false]);
                $this->recordAudit->handle(
                    PlatformAuditAction::AdminRevoked,
                    ['type' => 'user', 'id' => $locked->id, 'label' => $locked->email],
                    ['reason' => 'two_factor_disabled'],
                );
            }

            $locked->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
            ])->save();

            return $wasEnabled;
        });

        if ($wasEnabled) {
            $user->refresh()->notify(new TwoFactorDisabled);
        }
    }
}
