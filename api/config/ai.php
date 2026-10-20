<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider and model
    |--------------------------------------------------------------------------
    |
    | All AI calls go through App\Ai\Contracts\AiClient. "anthropic" calls the
    | Claude Messages API; "fake" returns canned responses (tests, e2e smoke).
    |
    */

    'provider' => env('AI_PROVIDER', 'anthropic'),

    'model' => env('AI_MODEL', 'claude-haiku-5-5'),

    // Seconds for one HTTP call. Keep it below the job timeout (OrganizationJob),
    // which in turn stays below the queue's retry_after (90s by default).
    'timeout' => (int) env('AI_TIMEOUT', 60),

    'max_tokens' => 4000,

    /*
    | Server-side refusal fallback: "default" or empty to disable. Only sent
    | for models that support it.
    */
    'fallbacks' => env('AI_FALLBACKS') ?: null,

    'fallback_models' => ['claude-opus-5-5', 'claude-sonnet-5-5'],

    // AI calls (and AI requests from the UI) per organization per minute.
    'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 5),

    /*
    | Monthly AI budget per organization in USD (summed from ai_usage.cost_usd
    | since the start of the UTC month). Organizations can override it
    | (organizations.ai_monthly_budget_usd); 0 turns AI off.
    */
    // Blank falls back to 5 too, so an empty key doesn't turn AI off by accident ("0" still does).
    'monthly_budget_usd' => (float) (filled(env('AI_MONTHLY_BUDGET_USD')) ? env('AI_MONTHLY_BUDGET_USD') : 5),

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => 'https://api.anthropic.com',
            'version' => '2023-06-01',
        ],
    ],

    /*
    | USD per 1M tokens, used for ai_usage.cost_usd. A model missing here is
    | recorded with a null cost.
    */
    'pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
    ],

];
