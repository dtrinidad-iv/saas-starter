<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Who may manage members of the current organization. The target membership is
 * always looked up inside the current organization before these checks run.
 * The "keep at least one owner" rule lives in the actions, not here.
 */
class MembershipPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->role() !== null;
    }

    public function update(User $user, Membership $membership, OrganizationRole $newRole): bool
    {
        return match ($this->currentOrganization->role()) {
            OrganizationRole::Owner => true,
            OrganizationRole::Admin => $membership->role !== OrganizationRole::Owner
                && $newRole !== OrganizationRole::Owner,
            default => false,
        };
    }

    public function delete(User $user, Membership $membership): bool
    {
        // Anyone may leave.
        if ($membership->user_id === $user->id) {
            return true;
        }

        return match ($this->currentOrganization->role()) {
            OrganizationRole::Owner => true,
            OrganizationRole::Admin => $membership->role === OrganizationRole::Member,
            default => false,
        };
    }
}
