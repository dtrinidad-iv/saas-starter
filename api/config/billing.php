<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | All payment calls go through App\Billing\Contracts\BillingGateway.
    | "stripe" uses Laravel Cashier (keys in config/cashier.php); "fake" makes
    | no network calls and sends checkouts straight back to the success page
    | (tests, local, e2e smoke).
    |
    */

    'provider' => env('BILLING_PROVIDER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | An organization without a valid subscription is on "free". A paid plan
    | needs a Stripe price id. The plan's monthly AI budget is the ceiling: an
    | organization's own budget (organizations.ai_monthly_budget_usd) can only
    | lower it. The free budget is AI_MONTHLY_BUDGET_USD (config/ai.php).
    | display_price is only shown in the UI; Stripe charges the price's amount.
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => null,
            'display_price' => 'Free',
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => env('STRIPE_PRICE_PRO'),
            'display_price' => env('BILLING_PRO_DISPLAY_PRICE', '$19 / month'),
            'ai_monthly_budget_usd' => (float) env('BILLING_PRO_AI_BUDGET_USD', 50),
        ],
    ],

];
