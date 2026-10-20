<?php

namespace App\Actions\ProjectSummaries;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\ProjectSummaryStatus;
use App\Models\Project;
use App\Models\ProjectSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReviewProjectSummary
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * Approve or reject a draft. Approving only marks it approved (and retires the
     * previously approved one); it never changes the project itself.
     */
    public function handle(ProjectSummary $summary, User $reviewer, bool $approve): ProjectSummary
    {
        DB::transaction(function () use ($summary, $reviewer, $approve) {
            // Lock the project first so reviews of its summaries run one at a time.
            $project = Project::query()->whereKey($summary->project_id)->lockForUpdate()->firstOrFail();
            $locked = ProjectSummary::query()->whereKey($summary->id)->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === ProjectSummaryStatus::Draft, 409, 'Only a draft summary can be reviewed.');

            if ($approve) {
                ProjectSummary::query()
                    ->where('project_id', $locked->project_id)
                    ->where('status', ProjectSummaryStatus::Approved)
                    ->update(['status' => ProjectSummaryStatus::Rejected, 'updated_at' => now()]);
            }

            $locked->forceFill([
                'status' => $approve ? ProjectSummaryStatus::Approved : ProjectSummaryStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $this->recordAudit->handle(
                $approve ? AuditAction::SummaryApproved : AuditAction::SummaryRejected,
                ['type' => 'project', 'id' => $project->id, 'label' => $project->name],
                metadata: ['summary_id' => $locked->id],
                actor: $reviewer,
            );
        });

        return $summary->fresh(['requester', 'reviewer']);
    }
}
