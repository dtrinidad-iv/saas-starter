<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\ProjectSummary;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Summaries are scoped to the current organization by BelongsToOrganization.
 * Reading them follows who may view the project; reviewing, who may edit it; generating
 * (an AI call) also needs ai.use.
 */
class ProjectSummaryPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projects,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    public function view(User $user, Project $project): bool
    {
        return $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->currentOrganization->can(Permission::AiUse) && $this->projects->update($user, $project);
    }

    public function review(User $user, ProjectSummary $summary): bool
    {
        return $summary->project !== null && $this->projects->update($user, $summary->project);
    }
}
