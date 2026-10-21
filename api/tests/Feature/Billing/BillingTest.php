<?php

use App\Billing\Contracts\BillingGateway;
use App\Billing\CreditPack;
use App\Billing\Exceptions\BillingUnavailable;
use App\Billing\InvoicePage;
use App\Billing\Plan;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5, 'app.frontend_url' => 'http://app.test']);
    [$this->owner, $this->acme] = userInOrganization();
    Sanctum::actingAs($this->owner);
});

it('shows a new organization on the free plan', function () {
    $this->getJson('/api/billing')
        ->assertOk()
        ->assertJsonPath('data.plan.key', 'free')
        ->assertJsonPath('data.subscription', null)
        ->assertJsonPath('data.payment_method', null)
        ->assertJsonPath('data.has_billing_account', false)
        ->assertJsonPath('data.plans.*.key', ['free', 'pro'])
        ->assertJsonPath('data.plans.1.ai_monthly_budget_usd', 50)
        ->assertJsonPath('data.ai_budget.limit_usd', 5)
        ->assertJsonMissingPath('data.plans.1.price_id');
});

it('shows an active subscription as Pro with the Pro AI budget', function () {
    subscribe($this->acme);
    $this->acme->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242'])->save();

    $this->getJson('/api/billing')
        ->assertOk()
        ->assertJsonPath('data.plan.key', 'pro')
        ->assertJsonPath('data.subscription.status', 'active')
        ->assertJsonPath('data.subscription.on_grace_period', false)
        ->assertJsonPath('data.payment_method', ['type' => 'visa', 'last_four' => '4242'])
        ->assertJsonPath('data.has_billing_account', true)
        ->assertJsonPath('data.ai_budget.limit_usd', 50);
});

it('keeps Pro until a canceled subscription ends, then goes back to free', function () {
    subscribe($this->acme, ['ends_at' => now()->addDays(3)]);

    $this->getJson('/api/billing')
        ->assertOk()
        ->assertJsonPath('data.plan.key', 'pro')
        ->assertJsonPath('data.subscription.on_grace_period', true);

    $this->travel(4)->days();

    $this->getJson('/api/billing')
        ->assertOk()
        ->assertJsonPath('data.plan.key', 'free')
        ->assertJsonPath('data.ai_budget.limit_usd', 5);
});

it('keeps Pro while a failed renewal is retried', function () {
    subscribe($this->acme, ['stripe_status' => 'past_due']);

    $this->getJson('/api/billing')->assertOk()->assertJsonPath('data.plan.key', 'pro');
});

it('treats a subscription that is not paid or has ended as free', function (array $attributes) {
    subscribe($this->acme, $attributes);

    $this->getJson('/api/billing')->assertOk()->assertJsonPath('data.plan.key', 'free');
})->with([
    'incomplete' => [['stripe_status' => 'incomplete']],
    'incomplete_expired' => [['stripe_status' => 'incomplete_expired']],
    'unpaid' => [['stripe_status' => 'unpaid']],
    // How the webhook leaves a deleted subscription (markAsCanceled).
    'canceled' => [fn () => ['stripe_status' => 'canceled', 'ends_at' => now()->subMinute()]],
]);

it('starts a checkout for the current organization and returns its URL', function () {
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])
        ->assertOk()
        ->assertExactJson(['data' => ['url' => 'http://app.test/billing?checkout=success']]);

    expect($this->billing->checkouts())->toBe([
        ['organization_id' => $this->acme->id, 'user_id' => $this->owner->id, 'plan' => 'pro'],
    ]);
});

it('validates the plan', function (array $payload) {
    $this->postJson('/api/billing/checkout', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan');

    $this->billing->assertNothingStarted();
})->with([
    'missing' => [[]],
    'free' => [['plan' => 'free']],
    'unknown' => [['plan' => 'enterprise']],
    'a price id' => [['plan' => 'price_pro_test']],
    'array' => [['plan' => ['pro']]],
]);

it('stays on Pro from a valid subscription when a newer one has ended', function () {
    subscribe($this->acme, ['created_at' => now()->subMonth()]);
    subscribe($this->acme, ['stripe_status' => 'canceled', 'ends_at' => now()->subDay(), 'created_at' => now()]);

    $this->getJson('/api/billing')->assertOk()->assertJsonPath('data.plan.key', 'pro');
});

it('lets an organization retry a checkout whose first payment did not go through', function () {
    subscribe($this->acme, ['stripe_status' => 'incomplete']);

    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertOk();
});

it('refuses a second subscription', function () {
    subscribe($this->acme);

    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan' => 'already has a subscription']);

    $this->billing->assertNothingStarted();
});

it('refuses a plan whose Stripe price is not configured', function () {
    config(['billing.plans.pro.price' => null]);

    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan' => 'isn\'t available']);

    $this->billing->assertNothingStarted();
});

it('opens the billing portal only for a billing customer', function () {
    $this->postJson('/api/billing/portal')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('billing');

    subscribe($this->acme);

    $this->postJson('/api/billing/portal')
        ->assertOk()
        ->assertExactJson(['data' => ['url' => 'http://app.test/billing']]);
    expect($this->billing->portals())->toBe([$this->acme->id]);
});

it('answers 503 when the payment provider is unavailable', function () {
    $this->app->instance(BillingGateway::class, new class implements BillingGateway
    {
        public function subscriptionCheckoutUrl(Organization $organization, User $user, Plan $plan, string $successUrl, string $cancelUrl): string
        {
            throw new BillingUnavailable;
        }

        public function creditsCheckoutUrl(Organization $organization, User $user, CreditPack $pack, string $successUrl, string $cancelUrl): string
        {
            throw new BillingUnavailable;
        }

        public function portalUrl(Organization $organization, string $returnUrl): string
        {
            throw new BillingUnavailable;
        }

        public function invoices(Organization $organization, int $limit, ?string $startingAfter): InvoicePage
        {
            throw new BillingUnavailable;
        }
    });

    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Billing is unavailable right now. Try again in a few minutes.', 'code' => 'billing_unavailable']);
    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])->assertStatus(503);

    subscribe($this->acme);
    $this->getJson('/api/billing/invoices')->assertStatus(503)->assertJsonPath('code', 'billing_unavailable');
});

it('lets only roles with billing.manage use billing', function (DefaultRole $role, int $status) {
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->getJson('/api/billing')->assertStatus($status);
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertStatus($status);
    $this->postJson('/api/billing/portal')->assertStatus($status === 200 ? 422 : 403);

    expect($this->billing->checkouts())->toHaveCount($status === 200 ? 1 : 0);
})->with([
    'owner' => [DefaultRole::Owner, 200],
    'admin' => [DefaultRole::Admin, 403],
    'member' => [DefaultRole::Member, 403],
]);

it('answers 403 before validating the plan for a role without billing.manage', function () {
    Sanctum::actingAs(addMember($this->acme, DefaultRole::Admin));

    $this->postJson('/api/billing/checkout', ['plan' => 'enterprise'])->assertForbidden();
});

it('lets a custom role with billing.manage use billing', function () {
    $role = createRole($this->acme, 'Finance', [Permission::BillingManage]);
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->getJson('/api/billing')->assertOk();
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertOk();
});

it('requires authentication', function () {
    auth()->forgetGuards();
    $this->app['auth']->guard('sanctum')->forgetUser();

    $this->withHeader('Authorization', '')->getJson('/api/billing')->assertUnauthorized();
});

it('never shows Stripe ids in organization responses', function () {
    subscribe($this->acme);
    $this->acme->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242'])->save();

    foreach (['/api/organizations', '/api/me'] as $uri) {
        expect($this->getJson($uri)->assertOk()->getContent())
            ->not->toContain($this->acme->stripe_id)
            ->not->toContain('4242');
    }
});
