<?php

namespace App\Actions\Admin\Concerns;

use App\Models\User;
use Illuminate\Validation\ValidationException;

trait ActsOnUsers
{
    /**
     * The user's row, locked. Call inside a transaction. Refuses platform admins
     * (yourself included): they're managed with `admin:grant` / `admin:revoke`, so
     * one admin can't lock out another from the browser.
     *
     * @throws ValidationException
     */
    private function lockedTarget(User $user): User
    {
        $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

        if ($locked->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'user' => 'Platform admins are managed from the command line. Remove admin with `admin:revoke` first.',
            ]);
        }

        return $locked;
    }

    /**
     * @return array{type: string, id: int, label: string}
     */
    private static function subject(User $user): array
    {
        return ['type' => 'user', 'id' => $user->id, 'label' => $user->email];
    }
}
