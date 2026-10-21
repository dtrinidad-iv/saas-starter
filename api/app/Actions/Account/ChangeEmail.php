<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\EmailChanged;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

class ChangeEmail
{
    /**
     * Change the email (the request checked the current password) and sign out every
     * other device. The new address is unverified until its link is followed; the old
     * one gets a notice.
     */
    public function handle(User $user, string $email, ?PersonalAccessToken $currentToken): User
    {
        $oldEmail = $user->email;

        if ($email === $oldEmail) {
            return $user;
        }

        $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();

        $user->tokens()
            ->when($currentToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
            ->delete();

        $user->sendEmailVerificationNotification();
        Notification::route('mail', $oldEmail)->notify(new EmailChanged($email));

        return $user;
    }
}
