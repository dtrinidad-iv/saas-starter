<?php

namespace App\Ai\Clients;

use App\Ai\AiBudget;
use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;

/**
 * Refuses calls once the current organization's monthly budget is used up
 * (AiBudgetExceeded), before they reach the provider or ai_usage.
 */
class BudgetedAiClient implements AiClient
{
    public function __construct(
        private readonly AiClient $inner,
        private readonly AiBudget $budget,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $this->budget->ensureAvailable();

        return $this->inner->generate($request);
    }
}
