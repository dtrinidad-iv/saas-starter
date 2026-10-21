<?php

namespace App\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Billing\Exceptions\BillingUnavailable;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe through Laravel Cashier: Checkout for payments, the Customer Portal for
 * everything after. Subscriptions are written by the webhook, not here.
 */
class StripeBillingGateway implements BillingGateway
{
    public function subscriptionCheckoutUrl(Organization $organization, User $user, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        return $this->call(function () use ($organization, $user, $plan, $successUrl, $cancelUrl) {
            $this->ensureCustomer($organization, $user);

            return $organization->newSubscription(Plans::SUBSCRIPTION, $plan->priceId)->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $organization->id,
            ])->url;
        });
    }

    public function portalUrl(Organization $organization, string $returnUrl): string
    {
        return $this->call(fn () => $organization->billingPortalUrl($returnUrl));
    }

    /**
     * One Stripe customer per organization, even when two people start a checkout at
     * the same time: the organization row is locked while the customer is created.
     */
    private function ensureCustomer(Organization $organization, User $user): void
    {
        if ($organization->hasStripeId()) {
            return;
        }

        DB::transaction(function () use ($organization, $user) {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            if (! $locked->hasStripeId()) {
                $locked->createAsStripeCustomer([
                    'email' => $user->email,
                    'metadata' => ['organization_id' => (string) $organization->id],
                ]);
            }

            $organization->forceFill(['stripe_id' => $locked->stripe_id])->syncOriginalAttribute('stripe_id');
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function call(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiErrorException $exception) {
            Log::warning('Stripe call failed.', ['error' => $exception->getMessage(), 'stripe_code' => $exception->getStripeCode()]);

            throw new BillingUnavailable(previous: $exception);
        }
    }
}
