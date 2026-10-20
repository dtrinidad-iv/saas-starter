<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Invite an email address to the organization and queue the invitation email.
     * Re-inviting the same address replaces its pending invitation (new link and expiry),
     * unless that invitation is for a role the inviter couldn't give themselves.
     *
     * $organization must be the current organization: the invitation's
     * organization_id is taken from it by BelongsToOrganization.
     */
    public function handle(User $inviter, Organization $organization, string $email, Role $role): OrganizationInvitation
    {
        $email = Str::lower(trim($email));

        if ($organization->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This person is already a member of the organization.',
            ]);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($inviter, $email, $role, $token) {
            $existing = OrganizationInvitation::pending()->with('role')->where('email', $email)->lockForUpdate()->get();
            $actor = $this->currentOrganization->role();

            if ($existing->contains(fn (OrganizationInvitation $pending) => $pending->role !== null
                && ($actor === null || ! $pending->role->isSubsetOf($actor)))) {
                throw ValidationException::withMessages([
                    'email' => 'This address already has a pending invitation for a role you can\'t give.',
                ]);
            }

            OrganizationInvitation::query()->whereKey($existing->modelKeys())->delete();

            $invitation = OrganizationInvitation::create([
                'email' => $email,
                'role_id' => $role->id,
                'token_hash' => OrganizationInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
            ]);

            $this->recordAudit->handle(
                AuditAction::MemberInvited,
                ['type' => 'invitation', 'id' => $invitation->id, 'label' => $email],
                metadata: ['role' => $role->name],
            );

            return $invitation;
        });

        Mail::to($email)->queue(new OrganizationInvitationMail(
            organizationName: $organization->name,
            inviterName: $inviter->name,
            roleName: $role->name,
            url: config('app.frontend_url').'/join/'.$token,
            expiresAt: $invitation->expires_at->toDayDateTimeString(),
        ));

        return $invitation->load(['inviter', 'role']);
    }
}
