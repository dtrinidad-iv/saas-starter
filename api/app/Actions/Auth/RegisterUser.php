<?php

namespace App\Actions\Auth;

use App\Models\User;

class RegisterUser
{
    /**
     * Create a user and issue their first API token.
     *
     * @param  array{name: string, email: string, password: string, device_name?: string|null}  $data
     * @return array{user: User, token: string}
     */
    public function handle(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
