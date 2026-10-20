<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectSummary;
use App\Models\User;

/**
 * Summaries are scoped to the current organization by BelongsToOrganization.
 * Anyone in it can read them; generating and reviewing follow who may edit the project.
 */
class ProjectSummaryPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, Project $project): bool
    {
        return $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->projects->update($user, $project);
    }

    public function review(User $user, ProjectSummary $summary): bool
    {
        return $summary->project !== null && $this->projects->update($user, $summary->project);
    }
}
