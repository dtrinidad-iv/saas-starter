<?php

namespace App\Listeners;

use App\Actions\ProjectSummaries\RequestProjectSummary;
use App\Events\ProjectCreated;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Runs in the request (it only writes a row and dispatches a job).
 */
class QueueProjectSummary
{
    public function __construct(private readonly RequestProjectSummary $requestSummary) {}

    public function handle(ProjectCreated $event): void
    {
        $project = $event->project;

        if (blank($project->description)) {
            return;
        }

        // Creating projects doesn't pass throttle:ai, so automatic summaries get the
        // same per-organization limit. Over it, the project is created without one
        // (it can still be generated from the project page).
        $key = 'ai-auto:'.$project->organization_id;
        if (RateLimiter::tooManyAttempts($key, config('ai.rate_limit_per_minute'))) {
            return;
        }
        RateLimiter::hit($key, 60);

        $this->requestSummary->handle($project, $project->creator);
    }
}
