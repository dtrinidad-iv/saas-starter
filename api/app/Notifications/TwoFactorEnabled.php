<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when two-factor authentication is turned on, so it can't happen unnoticed.
 */
class TwoFactorEnabled extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

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
            ->subject('Two-factor authentication was turned on')
            ->line('Two-factor authentication is now on for your '.config('app.name').' account. Logging in will ask for a code from your authenticator app.')
            ->line('Your other devices were signed out.')
            ->line("If you didn't do this, reset your password right away and contact support.");
    }
}
