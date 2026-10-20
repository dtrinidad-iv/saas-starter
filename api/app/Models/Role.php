<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A role in one organization. Members and invitations point at one.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property bool $is_owner Only set by CreateDefaultRoles.
 * @property Collection<int, Permission> $permissions Stored permissions; use grants() for checks.
 */
#[Fillable(['name', 'permissions'])]
class Role extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'is_owner' => false,
        'permissions' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
            'permissions' => AsEnumCollection::of(Permission::class),
        ];
    }

    /**
     * Everything this role may do: all permissions for the owner role.
     *
     * @return Collection<int, Permission>
     */
    public function grants(): Collection
    {
        return $this->is_owner ? collect(Permission::cases()) : $this->permissions;
    }

    public function has(Permission $permission): bool
    {
        return $this->grants()->contains($permission);
    }

    /**
     * Whether every permission in $permissions is granted by this role.
     *
     * @param  iterable<Permission>  $permissions
     */
    public function covers(iterable $permissions): bool
    {
        return collect($permissions)->every(fn (Permission $permission) => $this->has($permission));
    }

    /**
     * Whether $other can do everything this role can. Only an owner role covers an owner role.
     */
    public function isSubsetOf(Role $other): bool
    {
        if ($this->is_owner) {
            return $other->is_owner;
        }

        return $other->covers($this->grants());
    }

    /**
     * Whether $other can do everything this role can, and more.
     */
    public function isStrictSubsetOf(Role $other): bool
    {
        return $this->isSubsetOf($other) && ! $other->isSubsetOf($this);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }
}
