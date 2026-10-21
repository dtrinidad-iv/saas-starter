<?php

namespace App\Actions\Account;

use App\Actions\Auth\CompleteTwoFactorChallenge;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ReconfirmTwoFactor
{
    /**
     * Mark this token as having just passed a code from the app (platform admin
     * routes need a recent one). No recovery codes here: without the phone, log in
     * again with one. Wrong codes count toward the same per-account limit as login.
     *
     * @throws ValidationException two-factor off, too many wrong codes, or a wrong (or reused) code
     */
    public function handle(User $user, string $code, ?PersonalAccessToken $currentToken): void
    {
        $limiterKey = CompleteTwoFactorChallenge::limiterKey($user->id);
        if (RateLimiter::tooManyAttempts($limiterKey, CompleteTwoFactorChallenge::MAX_FAILURES_PER_USER)) {
            throw ValidationException::withMessages([
                'code' => ['Too many wrong codes for this account. Try again in '.ceil(RateLimiter::availableIn($limiterKey) / 60).' minutes.'],
            ]);
        }

        try {
            DB::transaction(function () use ($user, $code, $currentToken) {
                // Locked, so the same code can't be used twice at once (here or at login).
                $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

                if (! $user->hasTwoFactorEnabled()) {
                    throw ValidationException::withMessages([
                        'two_factor' => ['Turn on two-factor authentication first.'],
                    ]);
                }

                // Only a real API token can carry the mark (not a first-party session).
                abort_if($currentToken === null, 403, 'Log in with a token to confirm a code.');

                $step = Totp::verify($user->two_factor_secret, $code, $user->two_factor_last_used_step);
                if ($step === null) {
                    throw ValidationException::withMessages([
                        'code' => ["That code isn't right."],
                    ]);
                }

                $user->forceFill(['two_factor_last_used_step' => $step])->save();
                $currentToken->forceFill(['two_factor_at' => now()])->save();
            });
        } catch (ValidationException $exception) {
            if (array_key_exists('code', $exception->errors())) {
                CompleteTwoFactorChallenge::recordFailure($user->id);
            }

            throw $exception;
        }

        RateLimiter::clear($limiterKey);
    }
}
