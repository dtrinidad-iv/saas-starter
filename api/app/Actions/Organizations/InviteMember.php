<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    /**
     * Invite an email address to the organization and queue the invitation email.
     * Re-inviting the same address replaces its pending invitation (new link and expiry).
     *
     * $organization must be the current organization: the invitation's
     * organization_id is taken from it by BelongsToOrganization.
     */
    public function handle(User $inviter, Organization $organization, string $email, OrganizationRole $role): OrganizationInvitation
    {
        $email = Str::lower(trim($email));

        if ($organization->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This person is already a member of the organization.',
            ]);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($inviter, $email, $role, $token) {
            OrganizationInvitation::pending()->where('email', $email)->delete();

            return OrganizationInvitation::create([
                'email' => $email,
                'role' => $role,
                'token_hash' => OrganizationInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
            ]);
        });

        Mail::to($email)->queue(new OrganizationInvitationMail(
            organizationName: $organization->name,
            inviterName: $inviter->name,
            role: $role,
            url: config('app.frontend_url').'/join/'.$token,
            expiresAt: $invitation->expires_at->toDayDateTimeString(),
        ));

        return $invitation->load('inviter');
    }
}
