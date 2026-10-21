<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when support made the user an Owner of an organization (MakeMemberOwner).
 */
class MadeOwnerByAdmin extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $organizationName) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're now an Owner of {$this->organizationName}")
            // The name is user input: escaped so it can't add links or formatting to the mail.
            ->line('Support made you an Owner of '.addcslashes($this->organizationName, '\\`*_{}[]()<>#+-.!|~').' on '.config('app.name').'. Owners have every permission, including members, roles and billing.')
            ->action('Open '.config('app.name'), config('app.frontend_url'));
    }
}
