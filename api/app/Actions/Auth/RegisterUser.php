<?php

namespace App\Actions\Auth;

use App\Actions\Organizations\CreateOrganization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterUser
{
    public function __construct(private readonly CreateOrganization $createOrganization) {}

    /**
     * Create a user with a personal organization and issue their first API token.
     *
     * @param  array{name: string, email: string, password: string, device_name?: string|null}  $data
     * @return array{user: User, token: string}
     */
    public function handle(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $this->createOrganization->handle($user, "{$user->name}'s organization");

            return $user;
        });

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
