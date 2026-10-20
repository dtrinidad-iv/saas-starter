<?php

namespace App\Actions\Roles;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreateRole
{
    /**
     * Create a role in the current organization (set by BelongsToOrganization).
     *
     * @param  list<Permission>  $permissions
     */
    public function handle(string $name, array $permissions): Role
    {
        try {
            $role = Role::create(['name' => $name, 'permissions' => Permission::sorted($permissions)]);
        } catch (UniqueConstraintViolationException) {
            // Another request took the name after RoleRequest checked it.
            throw ValidationException::withMessages(['name' => 'There is already a role with this name.']);
        }

        return $role->loadCount('memberships');
    }
}
