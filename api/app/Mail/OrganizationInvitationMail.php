<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Takes plain values, so the queued email renders exactly what was true when the
 * invitation was sent. (Passing the model would also work: queued jobs restore
 * models without global scopes. But other org-scoped queries inside a job see
 * nothing until it calls CurrentOrganization::set().)
 */
class OrganizationInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $roleName,
        public readonly string $url,
        public readonly string $expiresAt,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviterName} invited you to join {$this->organizationName}",
        );
    }

    public function content(): Content
    {
        // Names are user input: Blade escapes HTML but not Markdown, so a name
        // like "[Verify](https://evil.example)" would otherwise become a link.
        // (Other keys than the public properties, which would overwrite them.)
        return new Content(markdown: 'mail.organization-invitation', with: [
            'organization' => self::escapeMarkdown($this->organizationName),
            'inviter' => self::escapeMarkdown($this->inviterName),
            'role' => self::escapeMarkdown($this->roleName),
        ]);
    }

    /**
     * Escape what can make links, autolinks, emphasis or code; nothing else, so the
     * plain-text part doesn't fill up with backslashes.
     */
    public static function escapeMarkdown(string $value): string
    {
        return addcslashes($value, '\\`*_[]<>');
    }
}
