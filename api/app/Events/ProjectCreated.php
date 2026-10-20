<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project was created. Listeners should stay small and dispatch jobs for real work
 * (e.g. QueueProjectSummary). Queued work should extend App\Jobs\OrganizationJob,
 * which sets CurrentOrganization so org-scoped queries work inside the job.
 */
class ProjectCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Project $project) {}
}
