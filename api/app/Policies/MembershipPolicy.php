<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Who may manage members of the current organization. The target membership is
 * always looked up inside the current organization before these checks run.
 * The "keep at least one owner" rule lives in the actions, not here.
 *
 * Non-owners only act on people whose role they cover, so nobody can hand out or
 * take away more than they have themselves.
 */
class MembershipPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->role() !== null;
    }

    /**
     * $newRole was looked up in the current organization.
     */
    public function update(User $user, Membership $membership, Role $newRole): bool
    {
        $actor = $this->currentOrganization->role();

        if ($actor === null) {
            return false;
        }

        return $actor->is_owner || (
            $actor->has(Permission::MembersChangeRole)
            && $membership->role->isSubsetOf($actor)
            && $newRole->isSubsetOf($actor)
        );
    }

    public function delete(User $user, Membership $membership): bool
    {
        // Anyone may leave.
        if ($membership->user_id === $user->id) {
            return true;
        }

        $actor = $this->currentOrganization->role();

        if ($actor === null) {
            return false;
        }

        // Strictly less than the actor: an admin can't remove another admin.
        return $actor->is_owner || (
            $actor->has(Permission::MembersRemove)
            && $membership->role->isStrictSubsetOf($actor)
        );
    }
}
