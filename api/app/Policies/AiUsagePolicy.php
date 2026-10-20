<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * AI usage rows are already limited to the current organization by BelongsToOrganization.
 */
class AiUsagePolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->can(Permission::AiUsageView);
    }

    public function manageBudget(User $user): bool
    {
        return $this->currentOrganization->can(Permission::AiBudgetManage);
    }
}
