<?php

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    /**
     * @param  array{name?: string, description?: string|null}  $data
     */
    public function handle(Project $project, array $data): Project
    {
        // One transaction with its audit entry (Auditable).
        DB::transaction(fn () => $project->update($data));

        return $project->load('creator');
    }
}
