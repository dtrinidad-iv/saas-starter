<?php

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class DeleteProject
{
    public function handle(Project $project): void
    {
        // One transaction with its audit entry (Auditable).
        DB::transaction(fn () => $project->delete());
    }
}
