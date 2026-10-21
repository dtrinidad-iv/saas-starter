<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetUserPassword
{
    public function __construct(private readonly IssueToken $issueToken) {}

    /**
     * Set a new password with a reset token, sign out every device and log in here.
     * Following the emailed link also proves the address, so it counts as verified.
     *
     * @param  array{email: string, token: string, password: string}  $data
     * @return array{user: User, token: string}
     *
     * @throws ValidationException invalid or expired token, or unknown email
     */
    public function handle(array $data): array
    {
        $resetUser = null;

        $status = Password::broker()->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) use (&$resetUser) {
                $user->forceFill(['password' => $password, 'remember_token' => null]);
                if (! $user->hasVerifiedEmail()) {
                    $user->email_verified_at = now();
                }
                $user->save();

                $user->tokens()->delete();
                $resetUser = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || $resetUser === null) {
            // Same message for an unknown email and a bad token.
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return ['user' => $resetUser, 'token' => $this->issueToken->handle($resetUser)];
    }
}
