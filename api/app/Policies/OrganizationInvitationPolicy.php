<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\CurrentOrganization;

class OrganizationInvitationPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->isOwnerOrAdmin();
    }

    public function create(User $user, OrganizationRole $role): bool
    {
        return match ($this->currentOrganization->role()) {
            OrganizationRole::Owner => true,
            OrganizationRole::Admin => $role !== OrganizationRole::Owner,
            default => false,
        };
    }

    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->isOwnerOrAdmin();
    }

    private function isOwnerOrAdmin(): bool
    {
        return in_array($this->currentOrganization->role(), [OrganizationRole::Owner, OrganizationRole::Admin], true);
    }
}
