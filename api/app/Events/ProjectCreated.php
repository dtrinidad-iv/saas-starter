<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A project was created. Listeners should stay small and dispatch jobs for real work.
 * A queued listener gets the project back (models are restored without global
 * scopes), but must call CurrentOrganization::set() before other org-scoped queries.
 */
class ProjectCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Project $project) {}
}
