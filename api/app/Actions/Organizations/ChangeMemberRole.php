<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class ChangeMemberRole
{
    use EnsuresAnOwnerRemains;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  User  $member  loaded through $organization->members() (membership attached)
     * @return User the member, re-loaded with the new role
     */
    public function handle(Organization $organization, User $member, Role $role): User
    {
        DB::transaction(function () use ($organization, $member, $role) {
            if ($member->membership->role->is_owner && ! $role->is_owner) {
                $this->ensureAnotherOwner($organization, $member, 'role_id');
            }

            // Re-read under a lock (after the owner rows, in the same order as
            // EnsuresAnOwnerRemains), so concurrent changes record the right old role.
            $current = $this->lockedRole($organization, $member);

            $organization->members()->updateExistingPivot($member->id, ['role_id' => $role->id]);

            if ($current->isNot($role)) {
                $this->recordAudit->handle(
                    AuditAction::MemberRoleChanged,
                    ['type' => 'member', 'id' => $member->id, 'label' => $member->name],
                    ['role' => ['old' => $current->name, 'new' => $role->name]],
                    organization: $organization,
                );
            }
        });

        // The acting user may have changed their own role.
        $this->currentOrganization->forget();

        $member = $organization->members()->findOrFail($member->id);
        $member->membership->setRelation('role', $role);

        return $member;
    }
}
