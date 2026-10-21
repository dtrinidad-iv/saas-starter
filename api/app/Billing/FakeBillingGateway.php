<?php

namespace App\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Models\Organization;
use App\Models\User;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * No network. Checkouts go straight to the success URL and the portal to its
 * return URL, without changing anything (only the webhook does that). Records
 * what was asked for. Used by tests and by BILLING_PROVIDER=fake for local and e2e runs.
 */
class FakeBillingGateway implements BillingGateway
{
    /** @var list<array{organization_id: int, user_id: int, plan: string}> */
    private array $checkouts = [];

    /** @var list<int> */
    private array $portals = [];

    /** @var list<array{organization_id: int, user_id: int, pack: string}> */
    private array $creditCheckouts = [];

    public function subscriptionCheckoutUrl(Organization $organization, User $user, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        $this->checkouts[] = ['organization_id' => $organization->id, 'user_id' => $user->id, 'plan' => $plan->key];

        return $successUrl;
    }

    public function creditsCheckoutUrl(Organization $organization, User $user, CreditPack $pack, string $successUrl, string $cancelUrl): string
    {
        $this->creditCheckouts[] = ['organization_id' => $organization->id, 'user_id' => $user->id, 'pack' => $pack->key];

        return $successUrl;
    }

    public function portalUrl(Organization $organization, string $returnUrl): string
    {
        if (! $organization->hasStripeId()) {
            throw new LogicException('The organization is not a billing customer.');
        }

        $this->portals[] = $organization->id;

        return $returnUrl;
    }

    /**
     * @return list<array{organization_id: int, user_id: int, plan: string}>
     */
    public function checkouts(): array
    {
        return $this->checkouts;
    }

    /**
     * @return list<array{organization_id: int, user_id: int, pack: string}>
     */
    public function creditCheckouts(): array
    {
        return $this->creditCheckouts;
    }

    public function assertNothingStarted(): void
    {
        Assert::assertSame([], $this->checkouts, 'A checkout was started.');
        Assert::assertSame([], $this->creditCheckouts, 'A credits checkout was started.');
        Assert::assertSame([], $this->portals, 'A billing portal session was started.');
    }

    /**
     * @return list<int> organization ids
     */
    public function portals(): array
    {
        return $this->portals;
    }
}
