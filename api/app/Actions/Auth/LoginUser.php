<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginUser
{
    /**
     * Verify credentials and issue a new API token.
     *
     * @param  array{email: string, password: string, device_name?: string|null}  $data
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function handle(array $data): array
    {
        $user = User::where('email', strtolower($data['email']))->first();

        // Same error for unknown email and wrong password so we don't reveal which accounts exist.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
