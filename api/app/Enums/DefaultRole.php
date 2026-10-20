<?php

namespace App\Enums;

/**
 * The roles every new organization starts with (CreateDefaultRoles). After that
 * they are ordinary roles: Admin and Member can be renamed, edited or deleted.
 * Only Owner is special (is_owner).
 */
enum DefaultRole: string
{
    case Owner = 'Owner';
    case Admin = 'Admin';
    case Member = 'Member';

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Stored permissions. The owner stores none: it has every permission implicitly.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [],
            // Changing roles and the AI budget stays with the owner.
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission) => ! in_array($permission, [Permission::RolesManage, Permission::AiBudgetManage], true),
            )),
            self::Member => [Permission::ProjectsView, Permission::ProjectsCreate, Permission::ProjectsUpdateOwn, Permission::AiUse],
        };
    }
}
