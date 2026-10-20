<?php

namespace App\Actions\ProjectSummaries;

use App\Enums\ProjectSummaryStatus;
use App\Jobs\GenerateProjectSummary;
use App\Models\Project;
use App\Models\ProjectSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RequestProjectSummary
{
    /**
     * A pending summary older than this is treated as lost (worker down, job gone):
     * longer than the job's worst case of retries, backoff and rate-limit releases.
     */
    public const STALE_AFTER_MINUTES = 15;

    /**
     * Queue a new summary for a project in the current organization, or return
     * the one already being generated. Open drafts are retired by the new request.
     */
    public function handle(Project $project, ?User $requester): ProjectSummary
    {
        [$summary, $created] = DB::transaction(function () use ($project, $requester) {
            // Lock the project so two clicks can't both start a summary. Scoped, so a
            // project from another organization fails here instead of being linked.
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $pending = $project->summaries()->where('status', ProjectSummaryStatus::Pending)->latest('id')->first();

            if ($pending !== null && $pending->created_at->gt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                return [$pending, false];
            }

            $pending?->forceFill(['status' => ProjectSummaryStatus::Failed, 'error' => 'unavailable'])->save();

            // A draft nobody reviewed is replaced by the new one.
            $project->summaries()->where('status', ProjectSummaryStatus::Draft)
                ->update(['status' => ProjectSummaryStatus::Rejected, 'updated_at' => now()]);

            $summary = new ProjectSummary;
            $summary->forceFill([
                'project_id' => $project->id,
                'status' => ProjectSummaryStatus::Pending,
                'requested_by' => $requester?->id,
            ])->save();

            return [$summary, true];
        });

        if ($created) {
            GenerateProjectSummary::dispatch($summary->organization_id, $summary->id, $requester?->id);
        }

        // The sync queue (tests) may have finished it already.
        return $summary->fresh(['requester', 'reviewer']);
    }
}
