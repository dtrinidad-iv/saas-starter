<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Projects are already limited to the current organization by BelongsToOrganization;
 * this decides what the user's role there may do with them.
 */
class ProjectPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->can(Permission::ProjectsView);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->currentOrganization->can(Permission::ProjectsView);
    }

    public function create(User $user): bool
    {
        return $this->currentOrganization->can(Permission::ProjectsCreate);
    }

    // Changing a project shows its content, so it also needs projects.view.
    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project) && (
            $this->currentOrganization->can(Permission::ProjectsUpdateAny)
            || ($this->currentOrganization->can(Permission::ProjectsUpdateOwn) && $project->created_by === $user->id)
        );
    }

    /**
     * AI writing help for the project form: for a new project (no $project) it follows
     * create, for an existing one update. It's an AI call, so it also needs ai.use.
     */
    public function writeWithAi(User $user, ?Project $project = null): bool
    {
        return $this->currentOrganization->can(Permission::AiUse)
            && ($project === null ? $this->create($user) : $this->update($user, $project));
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $this->currentOrganization->can(Permission::ProjectsDelete);
    }
}
