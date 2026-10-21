<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetUserPassword
{
    public function __construct(
        private readonly IssueToken $issueToken,
        private readonly IssueTwoFactorChallenge $issueChallenge,
    ) {}

    /**
     * Set a new password with a reset token, sign out every device and log in here.
     * Following the emailed link also proves the address, so it counts as verified.
     * With two-factor on it still needs a code: the inbox alone isn't enough to log in.
     *
     * @param  array{email: string, token: string, password: string}  $data
     * @return array{user: User, token: string}|array{two_factor: true, challenge: string}
     *
     * @throws ValidationException invalid or expired token, unknown email, or a suspended account
     */
    public function handle(array $data): array
    {
        $resetUser = null;
        $suspended = false;

        $status = Password::broker()->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) use (&$resetUser, &$suspended) {
                // A link sent before the suspension: used up, nothing changed.
                if ($user->isSuspended()) {
                    $suspended = true;

                    return;
                }

                $user->forceFill(['password' => $password, 'remember_token' => null]);
                if (! $user->hasVerifiedEmail()) {
                    $user->email_verified_at = now();
                }
                $user->save();

                $user->tokens()->delete();
                $resetUser = $user;
            },
        );

        // Only reached with a valid link, so it doesn't reveal the account to anyone else.
        if ($suspended) {
            throw ValidationException::withMessages(['email' => [User::SUSPENDED_MESSAGE]]);
        }

        if ($status !== Password::PASSWORD_RESET || $resetUser === null) {
            // Same message for an unknown email and a bad token.
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        if ($resetUser->hasTwoFactorEnabled()) {
            return ['two_factor' => true, 'challenge' => $this->issueChallenge->handle($resetUser)];
        }

        return ['user' => $resetUser, 'token' => $this->issueToken->handle($resetUser)];
    }
}
