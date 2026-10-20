<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Audit log entries are already limited to the current organization by BelongsToOrganization.
 */
class AuditLogPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->can(Permission::AuditLogView);
    }
}
