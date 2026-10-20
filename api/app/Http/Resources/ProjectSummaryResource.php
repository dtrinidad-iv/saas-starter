<?php

namespace App\Http\Resources;

use App\Enums\ProjectSummaryStatus;
use App\Models\ProjectSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectSummary
 */
class ProjectSummaryResource extends JsonResource
{
    // Error codes are internal; users get a short, generic reason.
    private const ERROR_MESSAGES = [
        'refusal' => 'The AI declined to summarize this project.',
        'invalid_output' => 'The AI returned a summary we could not use.',
        'max_tokens' => 'The AI returned a summary we could not use.',
        'unavailable' => 'The AI service was unavailable. Try again later.',
        'budget_exceeded' => 'This organization\'s monthly AI budget is used up.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'status' => $this->status,
            'summary' => $this->summary,
            'key_points' => $this->key_points ?? [],
            'model' => $this->model,
            'error' => $this->status === ProjectSummaryStatus::Failed
                ? (self::ERROR_MESSAGES[$this->error] ?? 'The summary could not be generated.')
                : null,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->only(['id', 'name'])),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->only(['id', 'name'])),
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'can' => [
                'review' => $request->user()->can('review', $this->resource),
                'regenerate' => $project !== null && $request->user()->can('create', [ProjectSummary::class, $project]),
            ],
        ];
    }
}
