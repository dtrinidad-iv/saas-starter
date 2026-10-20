<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Base for queued work that belongs to one organization. handle() runs with
 * CurrentOrganization set, so tenant-owned models are scoped as in a request.
 *
 * Timeouts nest: AI HTTP timeout (60s) < $timeout (75s) < queue retry_after (90s).
 */
abstract class OrganizationJob implements ShouldQueue
{
    use Queueable;

    // Rate-limited releases count as attempts, so allow more tries than exceptions.
    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $userId = null,
    ) {}

    /**
     * Seconds to wait before retrying after an exception.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetCurrentOrganization];
    }

    /**
     * Run code in this job's organization outside handle(), e.g. in failed(),
     * which doesn't go through job middleware.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    protected function inOrganization(Closure $callback): mixed
    {
        $organization = Organization::find($this->organizationId);

        if ($organization === null) {
            return null;
        }

        return app(CurrentOrganization::class)->runAs($organization, $callback);
    }
}
