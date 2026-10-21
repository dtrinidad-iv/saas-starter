<?php

namespace App\Actions\Billing;

use App\Ai\AiBudget;
use App\Billing\Plan;
use App\Billing\Plans;
use App\Models\Organization;

/**
 * The organization's plan, subscription and AI budget, for the billing page.
 * Read from our database only (kept in sync by the webhook): no Stripe call.
 */
class BillingOverview
{
    public function __construct(
        private readonly Plans $plans,
        private readonly AiBudget $budget,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Organization $organization): array
    {
        $subscription = $organization->subscription(Plans::SUBSCRIPTION);

        return [
            'plan' => $this->plans->for($organization)->toArray(),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->stripe_status,
                // Set when it's canceled: the plan stays until then (grace period).
                'ends_at' => $subscription->ends_at,
                'on_grace_period' => $subscription->onGracePeriod(),
            ],
            'payment_method' => $organization->pm_type === null ? null : [
                'type' => $organization->pm_type,
                'last_four' => $organization->pm_last_four,
            ],
            'has_billing_account' => $organization->hasStripeId(),
            'plans' => array_values(array_map(fn (Plan $plan) => $plan->toArray(), $this->plans->all())),
            'ai_budget' => $this->budget->summary($organization),
        ];
    }
}
