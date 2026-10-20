<?php

namespace App\Actions\Projects;

use App\Models\Project;

class UpdateProject
{
    /**
     * @param  array{name?: string, description?: string|null}  $data
     */
    public function handle(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->load('creator');
    }
}
