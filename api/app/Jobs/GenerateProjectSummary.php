<?php

namespace App\Jobs;

use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Prompts\ProjectSummaryPrompt;
use App\Enums\ProjectSummaryStatus;
use App\Models\ProjectSummary;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Turns a pending ProjectSummary into a draft. The output is validated before
 * it's saved, and stays a draft until a user approves it.
 */
class GenerateProjectSummary extends OrganizationJob
{
    public function __construct(int $organizationId, public readonly int $summaryId, ?int $userId = null)
    {
        parent::__construct($organizationId, $userId);
    }

    public function middleware(): array
    {
        return [...parent::middleware(), new RateLimited('ai')];
    }

    public function handle(AiClient $ai, ProjectSummaryPrompt $prompt): void
    {
        // Scoped to this job's organization; gone or already handled means nothing to do.
        $summary = ProjectSummary::query()->with('project')->find($this->summaryId);

        if ($summary === null || $summary->status !== ProjectSummaryStatus::Pending || $summary->project === null) {
            return;
        }

        // AiUnavailable is not caught: the job is retried with backoff.
        try {
            $response = $ai->generate($prompt->request($summary->project, $this->userId));
        } catch (AiRequestFailed $e) {
            $this->markFailed($summary, $e->errorCode);
            $this->fail($e);

            return;
        }

        $validator = Validator::make($response->json ?? [], $prompt->rules());

        if ($validator->fails()) {
            $this->markFailed($summary, 'invalid_output');
            $this->fail(new AiRequestFailed('Summary output failed validation: '.$validator->errors()->first(), 'invalid_output', $response));

            return;
        }

        $output = $validator->validated();

        $summary->forceFill([
            'status' => ProjectSummaryStatus::Draft,
            'summary' => trim($output['summary']),
            'key_points' => array_values(array_map(trim(...), $output['key_points'])),
            'model' => $response->model,
            'error' => null,
        ])->save();
    }

    /**
     * Retries used up or timed out.
     */
    public function failed(?Throwable $exception): void
    {
        $this->inOrganization(function () {
            $summary = ProjectSummary::query()->find($this->summaryId);

            if ($summary?->status === ProjectSummaryStatus::Pending) {
                $this->markFailed($summary, 'unavailable');
            }
        });
    }

    private function markFailed(ProjectSummary $summary, string $errorCode): void
    {
        $summary->forceFill(['status' => ProjectSummaryStatus::Failed, 'error' => $errorCode])->save();
    }
}
