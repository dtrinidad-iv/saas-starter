<?php

namespace App\Ai\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * The organization's monthly AI budget is used up. Thrown before calling the
 * provider, so nothing is spent or recorded. In a job it fails the work like any
 * AiRequestFailed; in a request it renders as 402.
 */
class AiBudgetExceeded extends AiRequestFailed
{
    public function __construct()
    {
        parent::__construct('The monthly AI budget is used up.', 'budget_exceeded');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'This organization\'s monthly AI budget is used up.',
            'code' => 'ai_budget_exceeded',
        ], 402);
    }
}
