<?php

namespace App\Actions\Admin;

use App\Actions\Audit\RecordAudit;
use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\AuditAction;
use App\Enums\PlatformAuditAction;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Notifications\MadeOwnerByAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MakeMemberOwner
{
    use EnsuresAnOwnerRemains;

    public function __construct(
        private readonly RecordPlatformAudit $recordPlatformAudit,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Give an existing member the organization's Owner role, for an organization
     * whose owners are gone or locked out. Never a platform admin (no making yourself,
     * or each other, Owner of someone else's organization). The organization's audit log shows it as
     * done by a platform admin; the platform log has who and why. The member gets an email.
     *
     * @return bool false when they were already Owner (nothing changed or logged)
     *
     * @throws ValidationException the member is a platform admin (the acting one included)
     */
    public function handle(User $admin, Organization $organization, User $member, string $reason): bool
    {
        if ($member->is($admin)) {
            throw ValidationException::withMessages(['user_id' => "You can't make yourself Owner of an organization."]);
        }

        $changed = DB::transaction(function () use ($organization, $member, $reason) {
            // Same order as every membership change (EnsuresAnOwnerRemains): the
            // organization, the owner rows, then this membership.
            $this->lockOrganization($organization);
            $this->lockOwnerRows($organization);
            // 404 when they aren't a member of this organization.
            $current = $this->lockedRole($organization, $member);

            // Not another platform admin either: two admins can't make each other Owner.
            if (User::query()->lockForUpdate()->findOrFail($member->id)->isPlatformAdmin()) {
                throw ValidationException::withMessages(['user_id' => "Platform admins can't be made Owner from the admin area."]);
            }

            if ($current->is_owner) {
                return false;
            }

            $owner = Role::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->where('is_owner', true)
                ->firstOrFail();

            $organization->members()->updateExistingPivot($member->id, ['role_id' => $owner->id]);

            $this->recordAudit->handle(
                AuditAction::MemberRoleChanged,
                ['type' => 'member', 'id' => $member->id, 'label' => $member->name],
                ['role' => ['old' => $current->name, 'new' => $owner->name]],
                organization: $organization,
                byPlatformAdmin: true,
            );

            $this->recordPlatformAudit->handle(
                PlatformAuditAction::OwnerAssigned,
                ['type' => 'organization', 'id' => $organization->id, 'label' => $organization->name],
                [
                    'reason' => $reason,
                    'member_id' => $member->id,
                    'member_email' => $member->email,
                    'old_role' => $current->name,
                ],
            );

            return true;
        });

        if ($changed) {
            $member->notify(new MadeOwnerByAdmin($organization->name));
        }

        return $changed;
    }
}
