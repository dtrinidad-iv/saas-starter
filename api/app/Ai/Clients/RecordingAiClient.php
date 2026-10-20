<?php

namespace App\Ai\Clients;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use App\Models\AiUsage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wraps the provider so every call, failed or not, is written to ai_usage
 * for the current organization. Features never record usage themselves.
 */
class RecordingAiClient implements AiClient
{
    /**
     * @param  array<string, array{input: float, output: float}>  $pricing  USD per 1M tokens.
     */
    public function __construct(
        private readonly AiClient $inner,
        private readonly string $model,
        private readonly array $pricing,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        try {
            $response = $this->inner->generate($request);
        } catch (AiRequestFailed $e) {
            $this->record($request, $e->response, 'failed', $e->errorCode);

            throw $e;
        } catch (AiUnavailable $e) {
            $this->record($request, null, 'failed', $e->errorCode);

            throw $e;
        } catch (Throwable $e) {
            $this->record($request, null, 'failed', 'exception');

            throw $e;
        }

        $this->record($request, $response, 'succeeded', null);

        return $response;
    }

    private function record(AiRequest $request, ?AiResponse $response, string $status, ?string $errorCode): void
    {
        $model = $response->model ?? $this->model;
        $inputTokens = $response->inputTokens ?? 0;
        $outputTokens = $response->outputTokens ?? 0;

        $usage = new AiUsage;
        $usage->forceFill([
            'user_id' => $request->userId,
            'feature' => $request->feature,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $this->cost($model, $inputTokens, $outputTokens),
            'status' => $status,
            'error_code' => $errorCode,
        ]);
        // organization_id comes from CurrentOrganization (BelongsToOrganization).
        $usage->save();
    }

    private function cost(string $model, int $inputTokens, int $outputTokens): ?string
    {
        $price = $this->pricing[$model] ?? null;

        if ($price === null) {
            Log::warning("No AI pricing configured for model [{$model}]; ai_usage cost left empty.");

            return null;
        }

        $cost = ($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000;

        return number_format($cost, 6, '.', '');
    }
}
