<?php

namespace App\Actions\Projects;

use App\Events\ProjectCreated;
use App\Models\Project;
use App\Models\User;

class CreateProject
{
    /**
     * Create a project in the current organization (set by BelongsToOrganization).
     *
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(User $creator, array $data): Project
    {
        $project = new Project($data);
        $project->created_by = $creator->id;
        $project->save();

        ProjectCreated::dispatch($project);

        return $project->load('creator');
    }
}
