<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\RecoveryCodeUsed;
use App\Notifications\TwoFactorLockedOut;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CompleteTwoFactorChallenge
{
    /** Wrong codes per account, across challenges and IPs, before it locks for a while. */
    public const MAX_FAILURES_PER_USER = 10;

    public const LOCKOUT_SECONDS = 900;

    public function __construct(private readonly IssueToken $issueToken) {}

    /**
     * Swap a login challenge and a code from the app (or a recovery code) for a
     * token. A challenge allows 5 tries and works once; each code and recovery
     * code works once; an account takes 10 wrong codes per 15 minutes.
     *
     * @param  array{challenge: string, code?: string|null, recovery_code?: string|null}  $data
     * @return array{user: User, token: string}
     *
     * @throws ValidationException expired challenge (key "challenge") or a wrong code
     */
    public function handle(array $data): array
    {
        $challenge = $data['challenge'];
        $pending = Cache::get(IssueTwoFactorChallenge::key($challenge));
        $userId = is_array($pending) ? $pending['user_id'] : null;
        $attempts = $userId === null ? false : Cache::increment(IssueTwoFactorChallenge::attemptsKey($challenge));

        if ($userId === null || $attempts === false || $attempts > IssueTwoFactorChallenge::MAX_ATTEMPTS) {
            $this->expire($challenge);
        }

        $limiterKey = self::limiterKey($userId);
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILURES_PER_USER)) {
            $this->expire($challenge, 'Too many wrong codes for this account. Try again in '.ceil(RateLimiter::availableIn($limiterKey) / 60).' minutes.');
        }

        $recoveryCode = $data['recovery_code'] ?? null;

        try {
            [$user, $remaining] = DB::transaction(function () use ($challenge, $pending, $userId, $data, $recoveryCode) {
                // Locked, so two requests can't both use the same code or the same challenge.
                $user = User::query()->lockForUpdate()->find($userId);

                if ($user === null || ! $user->hasTwoFactorEnabled()
                    || ! hash_equals($pending['fingerprint'] ?? '', IssueTwoFactorChallenge::fingerprint($user))
                    || ! Cache::has(IssueTwoFactorChallenge::key($challenge))) {
                    $this->expire($challenge);
                }

                if ($recoveryCode !== null) {
                    $remaining = $this->useRecoveryCode($user, $recoveryCode);
                } else {
                    $step = Totp::verify($user->two_factor_secret, (string) ($data['code'] ?? ''), $user->two_factor_last_used_step);
                    if ($step === null) {
                        throw ValidationException::withMessages([
                            'code' => ["That code isn't right."],
                        ]);
                    }

                    $user->forceFill(['two_factor_last_used_step' => $step])->save();
                    $remaining = null;
                }

                // Used up while the user row is still locked: a second request waits, then finds it gone.
                $this->forget($challenge);

                return [$user, $remaining];
            });
        } catch (ValidationException $exception) {
            if (array_key_exists('challenge', $exception->errors())) {
                // Again: with the database cache store, the rollback undid the forget.
                $this->forget($challenge);
            } else {
                self::recordFailure($userId);

                if ($attempts >= IssueTwoFactorChallenge::MAX_ATTEMPTS) {
                    $this->expire($challenge, "That code isn't right, and this login has expired after 5 tries. Enter your password again.");
                }
            }

            throw $exception;
        }

        RateLimiter::clear($limiterKey);

        if ($remaining !== null) {
            $user->notify(new RecoveryCodeUsed($remaining));
        }

        return ['user' => $user, 'token' => $this->issueToken->handle($user, $pending['device_name'] ?? null, twoFactor: $recoveryCode === null)];
    }

    /**
     * @return int how many are left
     */
    private function useRecoveryCode(User $user, string $code): int
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $hash = RecoveryCodes::hash($code);

        // No early exit, so the time doesn't tell which code matched.
        $index = null;
        foreach ($hashes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                $index = $i;
            }
        }

        if ($index === null) {
            throw ValidationException::withMessages([
                'recovery_code' => ["That recovery code isn't right, or it was already used."],
            ]);
        }

        unset($hashes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

        return count($hashes);
    }

    /**
     * The account's wrong-code counter, shared with ReconfirmTwoFactor.
     */
    public static function limiterKey(?int $userId): string
    {
        return 'two-factor:user:'.$userId;
    }

    /**
     * A wrong code: counts toward the account's limit, and the first time the
     * limit trips the account is told (someone may have the password).
     */
    public static function recordFailure(int $userId): void
    {
        $limiterKey = self::limiterKey($userId);
        RateLimiter::hit($limiterKey, self::LOCKOUT_SECONDS);

        if (RateLimiter::attempts($limiterKey) === self::MAX_FAILURES_PER_USER) {
            User::find($userId)?->notify(new TwoFactorLockedOut);
        }
    }

    /**
     * @throws ValidationException always
     */
    private function expire(string $challenge, string $message = 'This login has expired. Enter your password again.'): never
    {
        $this->forget($challenge);

        throw ValidationException::withMessages(['challenge' => [$message]]);
    }

    private function forget(string $challenge): void
    {
        Cache::forget(IssueTwoFactorChallenge::key($challenge));
        Cache::forget(IssueTwoFactorChallenge::attemptsKey($challenge));
    }
}
