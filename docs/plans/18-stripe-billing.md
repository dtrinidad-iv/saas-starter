# 18 — Stripe billing (Pro subscription + AI credit top-ups)

**Status:** WIP

## Context
The app has a per-organization monthly AI budget (`App\Ai\AiBudget`, default $5 from `config/ai.php`, optional per-org override `organizations.ai_monthly_budget_usd`) but no way to pay. We add Stripe so an organization can:
- **Subscribe** to a Pro plan (Free / Pro tiers) that raises its monthly AI budget.
- **Buy a one-time AI credit pack** (e.g. $10) that adds to the current month's AI budget.

Decisions taken: Laravel Cashier (new dependency, approved), Free/Pro tiers, credits as the one-time product.

## Key design choices
- **The organization is the billable, not the user.** `Organization` uses Cashier's `Billable` trait; `Cashier::useCustomerModel(Organization::class)` in `AppServiceProvider`. Subscriptions belong to `organization_id`. Billing always acts on the *current* organization (`CurrentOrganization`), never an id from the frontend.
- **Stripe-hosted pages only**: Stripe Checkout for subscribing and buying credits, Stripe Customer Portal for card changes / cancel / invoices. No card data touches our app, so no Stripe.js and no PCI scope beyond SAQ-A.
- **Webhooks are the source of truth.** Our own route `POST /api/stripe/webhook` (`Cashier::ignoreRoutes()`) goes to `StripeWebhookController`, which extends Cashier's controller (it syncs subscriptions). `VerifyStripeWebhook` always checks the signature and refuses everything when `STRIPE_WEBHOOK_SECRET` is blank (Cashier alone would skip the check). Credits come from a `handleCheckoutSessionCompleted` method on the same controller. The success redirect only refetches.
- **Stripe calls behind an interface** (`App\Billing\Contracts\BillingGateway`) with `StripeBillingGateway` (Cashier) and `FakeBillingGateway`, mirroring `AiClient` / `FakeAiClient`. `BILLING_PROVIDER=fake` for tests, local and e2e; tests never call Stripe (one test stubs stripe-php's HTTP client for the period-end cancel payload).
- **Plans in config** (`config/billing.php`): `free` (AI budget `AI_MONTHLY_BUDGET_USD`) and `pro` (`STRIPE_PRICE_PRO`, `BILLING_PRO_AI_BUDGET_USD`, default $50); credit packs keyed by id. The client sends a plan/pack *key*; never a price id or amount. `App\Billing\Plans::for()` picks the first valid subscription.
- **AI budget formula** (`AiBudget::limit`): `min(own budget ?? plan, plan)` + credits bought this UTC month. The plan is a ceiling, so an owner can cap spend but not give themselves more than they pay for (before, owners could set up to $10,000). `PUT /api/ai-budget` refuses more than the plan with an "upgrade" message, and checks the permission before validating. Credits expire at month end.
- **Past due keeps Pro** (`Cashier::keepPastDueSubscriptionsActive`) while Stripe retries a failed renewal.
- **Audit**: one `AuditAction::PlanChanged` (`plan: old → new`) recorded by `TrackPlanChange` around each subscription webhook (and `customer.deleted`), comparing the plan *as Stripe reports it* (`Plans::reportedFor`, status-based) before and after, under a row lock. Idempotent for retries; a scheduled cancel records nothing until Stripe deletes the subscription.
- **Idempotent credit grants**: `ai_credit_purchases` with unique `stripe_checkout_session_id`; the org comes from the session's Stripe `customer` → `organizations.stripe_id`.

## Steps (one commit each)

### 1. Cashier + subscriptions (api) — built
- `laravel/cashier` v16.9 (installed through a mirror because Packagist was unreachable; `composer.lock` entries verified against the upstream tags and pointed back at GitHub).
- Migrations: Cashier columns on `organizations` (`stripe_id` unique), `subscriptions` / `subscription_items` with an `organization_id` FK (`cascadeOnDelete`).
- `config/billing.php`, `app/Billing/{Plan, Plans, Contracts/BillingGateway, StripeBillingGateway, FakeBillingGateway, Exceptions/BillingUnavailable (503)}`.
- `Permission::BillingManage` (`billing.manage`), Owner only by default (Admin excluded in `DefaultRole`; no data migration needed).
- `OrganizationPolicy::manageBilling`, `BillingController` + Actions `BillingOverview`, `StartSubscriptionCheckout`, `OpenBillingPortal`, `TrackPlanChange`:
  - `GET /api/billing` (plan, subscription status / ends_at / grace period, card brand + last four, plans, AI budget). No Stripe call.
  - `POST /api/billing/checkout` `{plan}` → `{url}`; refused while a subscription is still billed.
  - `POST /api/billing/portal` → `{url}`; only for a Stripe customer.
  - `throttle:20,1` on the POSTs, `throttle:120,1` on the webhook.
- Webhook hardening: late `updated` events for an already canceled subscription are ignored; events without a customer are ignored; a second paid subscription logs a warning (two checkouts in two tabs can't be fully prevented).
- `ai-usage.vue`: the budget form's maximum is the plan's budget.
- Docs: `.env.example`, README (Stripe setup, env keys, feature row), CLAUDE.md (Billing conventions).

### 2. AI credit top-ups (api) — built
- Migration + model `AiCreditPurchase` (`BelongsToOrganization`, `pack`, `amount_usd`, `stripe_checkout_session_id` unique).
- `POST /api/billing/credits/checkout` `{pack}` → `{url}`; Checkout `mode=payment` with the pack key in metadata (set by the server).
- `StripeWebhookController::handleCheckoutSessionCompleted` → `GrantAiCredits`: only `mode=payment` and `payment_status=paid`, org from the customer id, pack validated against config, amount from config, idempotent on the session id, `RecordAudit(AuditAction::AiCreditsPurchased, organization: ...)`.
- `AiBudget::limit()` adds this month's credits (an own budget of 0 still turns AI off); `summary()` exposes `credits_usd`. The buyer is recorded only while a member of the organization.
- Credits are granted only when the session's `client_reference_id` (set by our checkout) is the customer's organization, and not sold while the own budget is $0.
- Docs: `.env.example`, README (Stripe setup, events to forward, env key, feature row), CLAUDE.md.
- **Known limitations** (follow-up if needed): refunds and disputes don't take credits back (handle in Stripe and adjust manually); credits expire at the end of the UTC month they're bought in, even if bought on the last day (the billing page says until when).

### 3. Billing page (web) — built
- `web/app/pages/billing.vue`: current plan + status, "Upgrade to Pro" / "Manage billing", credit packs with "Buy", AI budget meter; loading, error and no-permission states.
- `?checkout=success` → toast and refetch a few times until the webhook lands; `?checkout=canceled` → neutral note.
- Sidebar item "Billing" gated on `billing.manage`; `navigateTo(url, { external: true })`.
- `ai-usage.vue`: show credits in the budget. The API also returns `own_limit_usd` (the owner's budget without credits), which the budget form edits.
- The return polling stops on leaving the page or switching organization; credits compare against the amount saved before leaving for Checkout.

### 4. Docs and screenshot — built (with step 3)
- README feature row for credits and the billing page, screenshot `docs/screenshots/billing.png` (light, 1280×800, e2e demo data, fake provider); `.env.example` and CLAUDE.md for credits.

## Critical files
- New: `api/config/billing.php`, `api/app/Billing/*`, `api/app/Http/Controllers/Api/BillingController.php`, `api/app/Actions/Billing/*`, `api/app/Http/Controllers/StripeWebhookController.php`, `api/app/Http/Middleware/VerifyStripeWebhook.php`, `api/app/Models/AiCreditPurchase.php`, `api/app/Policies/OrganizationPolicy.php`, `web/app/pages/billing.vue`
- Changed: `api/app/Models/Organization.php`, `api/app/Ai/AiBudget.php`, `api/app/Enums/{Permission,AuditAction}.php`, `api/app/Providers/AppServiceProvider.php`, `api/routes/api.php`, `web/app/layouts/default.vue` (nav), `web/app/pages/ai-usage.vue`

## Verification
- Pest (`FakeBillingGateway`, no Stripe calls; webhooks posted with a valid `Stripe-Signature` built from a test secret):
  - permission table: only roles with `billing.manage` get checkout/portal/credits URLs; others 403.
  - org A vs B: user of A gets A's billing only; a webhook for B's customer credits B, never A; metadata naming another organization is ignored.
  - subscription webhooks create/cancel the `subscriptions` row and audit entries; `AiBudget::limit` switches Free ↔ Pro.
  - credit webhook: unpaid ignored, unknown pack ignored, duplicate event grants once, invalid signature 403; credits count only in their month.
  - invalid plan/pack keys → 422.
- `/verify` (Pint, Pest, lint, typecheck, build).
- Manual test-mode run with real Stripe keys + `stripe listen`: subscribe with `4242…`, see Pro budget; cancel in Portal; buy credits; check `stripe trigger` duplicates.
- `/e2e-smoke` with `BILLING_PROVIDER=fake`; reviewers in parallel: code, security, tenant isolation, database, UI.
