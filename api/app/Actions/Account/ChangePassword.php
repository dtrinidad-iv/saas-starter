<?php

namespace App\Actions\Account;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class ChangePassword
{
    /**
     * Set a new password (the request checked the current one) and sign out every
     * other device. The token of this request keeps working.
     */
    public function handle(User $user, string $password, ?PersonalAccessToken $currentToken): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => null])->save();

        $user->tokens()
            ->when($currentToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
            ->delete();
    }
}
