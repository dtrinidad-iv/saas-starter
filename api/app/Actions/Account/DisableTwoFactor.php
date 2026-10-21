<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\TwoFactorDisabled;
use Illuminate\Support\Facades\DB;

class DisableTwoFactor
{
    /**
     * Turn two-factor off (the request checked the password), or drop a setup
     * that wasn't finished. The account gets an email when it was on.
     */
    public function handle(User $user): void
    {
        $wasEnabled = DB::transaction(function () use ($user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $wasEnabled = $locked->hasTwoFactorEnabled();

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
