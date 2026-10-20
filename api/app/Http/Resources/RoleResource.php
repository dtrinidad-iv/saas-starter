<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * The short form used on members, invitations and organizations.
     *
     * @return array{id: int, name: string, is_owner: bool}
     */
    public static function summary(Role $role): array
    {
        return $role->only(['id', 'name', 'is_owner']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...static::summary($this->resource),
            // Everything the role grants: all permissions for the owner role.
            'permissions' => $this->grants()->map(fn (Permission $permission) => $permission->value)->values(),
            'members_count' => $this->whenCounted('memberships'),
            'created_at' => $this->created_at,
        ];
    }
}
