<?php

namespace App\Actions\Roles;

use App\Enums\Permission;
use App\Models\Role;
use App\Support\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class UpdateRole
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * @param  list<Permission>  $permissions
     */
    public function handle(Role $role, string $name, array $permissions): Role
    {
        try {
            $role->update(['name' => $name, 'permissions' => Permission::sorted($permissions)]);
        } catch (UniqueConstraintViolationException) {
            // Another request took the name after RoleRequest checked it.
            throw ValidationException::withMessages(['name' => 'There is already a role with this name.']);
        }

        // Members acting in this request may hold the role.
        $this->currentOrganization->forget();

        return $role->loadCount('memberships');
    }
}
