<?php

namespace App\Jobs\Middleware;

use App\Jobs\OrganizationJob;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;

/**
 * Runs an OrganizationJob inside its organization, so BelongsToOrganization
 * scopes and creates rows there. The organization id was stored when the job
 * was dispatched (from the server side, never from client input).
 */
class SetCurrentOrganization
{
    public function handle(OrganizationJob $job, Closure $next): mixed
    {
        $organization = Organization::find($job->organizationId);

        // Deleted since dispatch: nothing left to work on.
        if ($organization === null) {
            $job->delete();

            return null;
        }

        // Restores the previous organization afterwards (matters on the sync queue,
        // where the job runs inside a request or another job).
        return app(CurrentOrganization::class)->runAs($organization, fn () => $next($job));
    }
}
