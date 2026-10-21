<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\RecoveryCodesRegenerated;
use App\Support\RecoveryCodes;
use Illuminate\Validation\ValidationException;

class RegenerateRecoveryCodes
{
    /**
     * A new set of recovery codes; the old ones stop working. The account gets an
     * email, since a new set is a lasting way in.
     *
     * @return list<string> shown once
     *
     * @throws ValidationException two-factor is off
     */
    public function handle(User $user): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_factor' => ['Two-factor authentication is off.'],
            ]);
        }

        $codes = RecoveryCodes::generate();
        $user->forceFill(['two_factor_recovery_codes' => RecoveryCodes::hashAll($codes)])->save();
        $user->notify(new RecoveryCodesRegenerated);

        return $codes;
    }
}
