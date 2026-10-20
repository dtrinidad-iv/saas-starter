<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProjectSummaries\RequestProjectSummary;
use App\Actions\ProjectSummaries\ReviewProjectSummary;
use App\Enums\ProjectSummaryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectSummaryResource;
use App\Models\Project;
use App\Models\ProjectSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AI summaries of a project in the current organization. {project} and
 * {summary} resolve through the organization scope, and {summary} must belong
 * to {project} (scoped bindings), so anything else is a 404.
 */
class ProjectSummaryController extends Controller
{
    /**
     * The latest summary (any status) as data, and the approved one, which is the
     * project's summary until a newer one is approved. Either may be null.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', [ProjectSummary::class, $project]);

        $resource = function (?ProjectSummary $summary) use ($request, $project) {
            return $summary?->setRelation('project', $project)->loadMissing(['requester', 'reviewer'])
                ->toResource(ProjectSummaryResource::class)->resolve($request);
        };

        $latest = $project->latestSummary()->first();
        $approved = $latest?->status === ProjectSummaryStatus::Approved ? $latest : $project->approvedSummary()->first();

        return response()->json([
            'data' => $resource($latest),
            'approved' => $resource($approved),
        ]);
    }

    public function store(Request $request, Project $project, RequestProjectSummary $action): JsonResponse
    {
        Gate::authorize('create', [ProjectSummary::class, $project]);

        $summary = $action->handle($project, $request->user());
        $summary->setRelation('project', $project);

        return (new ProjectSummaryResource($summary))->response()->setStatusCode(202);
    }

    public function approve(Request $request, Project $project, ProjectSummary $summary, ReviewProjectSummary $action): ProjectSummaryResource
    {
        return $this->review($request, $project, $summary, $action, true);
    }

    public function reject(Request $request, Project $project, ProjectSummary $summary, ReviewProjectSummary $action): ProjectSummaryResource
    {
        return $this->review($request, $project, $summary, $action, false);
    }

    private function review(Request $request, Project $project, ProjectSummary $summary, ReviewProjectSummary $action, bool $approve): ProjectSummaryResource
    {
        $summary->setRelation('project', $project);
        Gate::authorize('review', $summary);

        $summary = $action->handle($summary, $request->user(), $approve);
        $summary->setRelation('project', $project);

        return new ProjectSummaryResource($summary);
    }
}
