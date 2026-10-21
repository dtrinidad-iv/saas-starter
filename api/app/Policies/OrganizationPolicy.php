<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Billing always acts on the current organization, never one named by the client.
 */
class OrganizationPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function manageBilling(User $user): bool
    {
        return $this->currentOrganization->can(Permission::BillingManage);
    }
}
