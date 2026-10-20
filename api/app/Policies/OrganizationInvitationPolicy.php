<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;

class OrganizationInvitationPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->can(Permission::MembersInvite);
    }

    /**
     * $role was looked up in the current organization. Only roles the inviter covers.
     */
    public function create(User $user, Role $role): bool
    {
        $actor = $this->currentOrganization->role();

        return $actor !== null && $actor->has(Permission::MembersInvite) && $role->isSubsetOf($actor);
    }

    /**
     * Only invitations for roles the actor covers (or whose role was deleted).
     */
    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        $actor = $this->currentOrganization->role();

        return $actor !== null && $actor->has(Permission::MembersInvite)
            && ($invitation->role === null || $invitation->role->isSubsetOf($actor));
    }
}
