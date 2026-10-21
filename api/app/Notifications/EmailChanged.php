<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the old address when someone changes their account's email, so a
 * takeover doesn't go unnoticed. The new address is only shown masked.
 */
class EmailChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $newEmail) {}

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
            ->subject('Your email address was changed')
            ->line('The email address of your '.config('app.name').' account was changed to '.self::mask($this->newEmail).'.')
            ->line("If you didn't do this, contact support right away: someone else may have access to your account.");
    }

    /**
     * "a***@example.com": enough to recognize your own address, not to learn someone else's.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
