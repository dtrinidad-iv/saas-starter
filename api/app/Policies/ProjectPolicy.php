<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Projects are already limited to the current organization by BelongsToOrganization;
 * this decides what each role may do with them.
 */
class ProjectPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, Project $project): bool
    {
        return $this->role() !== null;
    }

    public function create(User $user): bool
    {
        return $this->role() !== null;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->isOwnerOrAdmin()
            || ($this->role() === OrganizationRole::Member && $project->created_by === $user->id);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->isOwnerOrAdmin();
    }

    private function role(): ?OrganizationRole
    {
        return $this->currentOrganization->role();
    }

    private function isOwnerOrAdmin(): bool
    {
        return in_array($this->role(), [OrganizationRole::Owner, OrganizationRole::Admin], true);
    }
}
