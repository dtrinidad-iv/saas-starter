<?php

namespace App\Mail;

use App\Enums\OrganizationRole;
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
        public readonly OrganizationRole $role,
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
        return new Content(markdown: 'mail.organization-invitation');
    }
}
