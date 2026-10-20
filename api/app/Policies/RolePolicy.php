<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Roles are limited to the current organization by BelongsToOrganization.
 * The owner role is fixed. Other roles are managed with roles.manage, and a
 * non-owner can only grant permissions they have, and only change roles that can
 * do less than theirs (not their own, nor a peer's with the same permissions:
 * emptying it would remove that peer, which MembershipPolicy doesn't allow).
 */
class RolePolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->role() !== null;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function create(User $user, array $permissions): bool
    {
        $actor = $this->manager();

        return $actor !== null && $actor->covers($permissions);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function update(User $user, Role $role, array $permissions): bool
    {
        return $this->mayChange($role) && $this->manager()->covers($permissions);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->mayChange($role);
    }

    private function mayChange(Role $role): bool
    {
        $actor = $this->manager();

        if ($actor === null || $role->is_owner) {
            return false;
        }

        return $actor->is_owner || ($role->id !== $actor->id && $role->isStrictSubsetOf($actor));
    }

    /**
     * The acting user's role, if it may manage roles at all.
     */
    private function manager(): ?Role
    {
        $actor = $this->currentOrganization->role();

        return $actor?->has(Permission::RolesManage) ? $actor : null;
    }
}
