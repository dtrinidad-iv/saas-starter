<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\ProjectChatMessage;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    use EnsuresAnOwnerRemains;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Remove a member (or let them leave), with their private AI chats there. If this
     * was their current organization, move them to another one they belong to, or to none.
     *
     * @param  User  $member  loaded through $organization->members() (membership attached)
     */
    public function handle(Organization $organization, User $member): void
    {
        DB::transaction(function () use ($organization, $member) {
            if ($member->membership->role->is_owner) {
                $this->ensureAnotherOwner($organization, $member, 'member');
            }

            // Re-read under a lock (after the owner rows, in the same order as
            // EnsuresAnOwnerRemains), so concurrent changes record the right old role.
            $current = $this->lockedRole($organization, $member);

            $organization->members()->detach($member->id);

            // Their private AI chats here go with them (explicit organization: it may not be current).
            ProjectChatMessage::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->where('user_id', $member->id)
                ->delete();

            $this->recordAudit->handle(
                AuditAction::MemberRemoved,
                ['type' => 'member', 'id' => $member->id, 'label' => $member->name],
                metadata: ['role' => $current->name],
                organization: $organization,
            );

            if ($member->current_organization_id === $organization->id) {
                $member->forceFill([
                    'current_organization_id' => $member->organizations()->orderBy('name')->value('organizations.id'),
                ])->save();
            }
        });

        $this->currentOrganization->forget();
    }
}
