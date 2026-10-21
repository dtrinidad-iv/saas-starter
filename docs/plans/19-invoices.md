# 19 — Invoices

**Status:** Done
**Commits:** `01644d1` (api), `c3d538f` (web)

## Goal
Owners (anyone with `billing.manage`) can see the current organization's invoices inside the app (date, number, what it was for, amount, status) and open each one on Stripe (hosted invoice page and PDF). Today they're only reachable through the Customer Portal. Covers subscription invoices and the AI credit pack receipts (Checkout already creates those with `invoice_creation`).

Out of scope: refunds, credit notes, editing billing details or tax ids, emailing invoices, and storing invoices locally.

## Decisions
- **Read live from Stripe, no `invoices` table.** Stripe is the source of truth and already renders the documents; a local copy would need its own webhook sync for something that changes rarely and is read rarely. The cost is one Stripe call per page load; it's behind `BillingGateway` so tests and `BILLING_PROVIDER=fake` never reach Stripe.
- **A separate page, `/billing/invoices`**, linked from the billing page's Plan header ("Invoices", next to "Payment details and invoices", which becomes "Payment details"). It also gets its own sidebar item, "Invoices" right under "Billing" (same `billing.manage` gate); "Billing" is active only on `/billing`. Keeps the billing page short as the list grows.
- **Cursor pagination, 20 per page, "Load more".** Stripe lists newest first with `starting_after`; the API returns `next_cursor` (the last invoice id, or `null`). The cursor is validated as `in_[A-Za-z0-9_]+`; the listing is always filtered by the organization's own Stripe customer, so another organization's invoice id as a cursor can't return their invoices.
- **Drafts are hidden** (filtered after Stripe returns the page, so a page can be shorter than 20; the empty state says so when there's more); open (unpaid) invoices show as "Due" with a "Pay" link to the hosted page, so a past-due owner can pay from here.
- **Links are only passed on when they're Stripe's** (`https://` and a host of `stripe.com` or a subdomain); anything else becomes `null`. The frontend opens them in a new tab with `rel="noopener"`.
- **No audit entry** (read-only), **no new permission** (`billing.manage` already reads "Manage the plan, payment details and invoices"), **no new dependency**.
- **An organization without a Stripe customer** gets an empty list without a Stripe call.

## Backend (`api/`)
- `app/Billing/Invoice.php`: readonly value object (`id`, `number`, `status` (`paid` | `open` | `void` | `uncollectible`), `total` (cents), `currency`, `created_at`, `description` (first line item's description, e.g. "Pro" or "AI credits $10"), `hosted_url`, `pdf_url`).
- `app/Billing/InvoicePage.php`: `list<Invoice> $invoices`, `?string $nextCursor`.
- `BillingGateway::invoices(Organization $organization, int $limit, ?string $startingAfter): InvoicePage` (throws `BillingUnavailable`).
  - `StripeBillingGateway`: `Organization::stripe()->invoices->all(['customer' => ..., 'limit' => $limit, 'starting_after' => ...])` through `call()`; Stripe's `has_more` gives the next cursor (the last id fetched); maps Stripe invoices to `Invoice`, applying the Stripe-host rule to the URLs. A cursor Stripe doesn't know (`resource_missing` on `starting_after`) returns an empty page, not a 503.
  - `FakeBillingGateway`: returns what a test set with `setInvoices(Organization, list<Invoice>)`; otherwise, for local and e2e runs, one paid invoice per subscription and per `AiCreditPurchase` of the organization (deterministic ids `in_fake_…`, `hosted_url` pointing back to `FRONTEND_URL/billing/invoices`, `pdf_url` null). Records each call (`invoiceRequests()`) for the isolation tests.
- `app/Actions/Billing/ListInvoices.php`: no Stripe id → empty page; else `gateway->invoices($org, 20, $cursor)`.
- `app/Http/Requests/Billing/ListInvoicesRequest.php`: `starting_after` nullable, `regex:/^in_[A-Za-z0-9_]+\z/`, max 255.
- `app/Http/Resources/InvoiceResource.php`: `id, number, status, total, currency, description, created_at (ISO), hosted_url, pdf_url`; the controller returns the collection with `meta.next_cursor`.
- `BillingController::invoices` with `Gate::authorize('manageBilling', Organization::class)` before validating (as `PUT /api/ai-budget` does), current organization from `CurrentOrganization`.
- Route `GET /api/billing/invoices` in the existing billing group, `throttle:30,1` (each call hits Stripe).

## Frontend (`web/`)
- `types/api.ts`: `Invoice`, `InvoiceStatus`.
- `pages/billing/invoices.vue` (`billing.vue` moves to `pages/billing/index.vue` so both live under `/billing`; same URL):
  - Header "Invoices" with a back link to Billing.
  - `UTable`: Date, Number, Description, Amount (`Intl` with the invoice's currency, fixed `en-US` / UTC like `billing.vue`), Status badge (Paid green, Due warning, Void / Uncollectible neutral), actions: "View" (hosted page) and "PDF" when present, "Pay" instead of "View" for open ones.
  - "Load more" button while `next_cursor` is set; appended rows, loading state on the button.
  - States: loading, error with Retry (503 → "Stripe is unavailable, try again"), empty ("No invoices yet. They appear here after the first payment."), no permission (same alert as billing).
  - Refetches on organization switch (`refreshNuxtData`) and resets the cursor.
  - At 390px the table hides Number and Description columns.
- `pages/billing/index.vue`: "Invoices" link in the Plan header; portal button label → "Payment details".

## Tests (`tests/Feature/Billing/InvoicesTest.php`, `BillingIsolationTest.php`)
- Lists invoices from the fake in order with every field; `next_cursor` set when the gateway says there's more, and `starting_after` is passed through.
- No Stripe customer → empty list and no gateway call.
- Invalid cursor (`abc`, `in_../x`) → 422; non-Stripe URLs from the gateway come back `null` (tested on the `StripeBillingGateway` mapping with a stubbed Stripe HTTP client, as plan 18 did for cancel).
- `BillingUnavailable` → 503.
- Permission table: Owner 200; Admin and Member 403; a custom role with `billing.manage` 200; 403 comes before 422 for a bad cursor.
- **Org A vs B**: org A's owner gets A's invoices only, with `organization_id=B` in the query ignored; the gateway is only ever called with org A; B's invoice id as `starting_after` is passed to org A's own listing (Stripe filters by customer), never switches organizations.
- Unauthenticated 401.

## README screenshots
New `docs/screenshots/invoices.png`: the invoices page with a Pro subscription invoice and an AI credits invoice (fake provider, plan 17's e2e demo data, light mode, 1280×800, `e2e-*` accounts cleaned up). `billing.png` retaken (the Plan header links change). README: feature row updated (`GET /api/billing/invoices`, `/billing/invoices`) and a line in "Screenshots".

## Verification
`/verify` (Pint, Pest, lint, typecheck, build); `/e2e-smoke` with `BILLING_PROVIDER=fake` (owner sees invoices, member gets the no-access state, org switch reloads); manual test-mode check with real Stripe keys (a Pro invoice and a credits receipt, links open on Stripe, PDF downloads), done by the user in Stripe test mode (2026-10-21). Reviewers in parallel: code, security, tenant isolation, UI (database not needed: no migrations or queries beyond the fake).

## Commits after approval
- `feat(api): list the organization's invoices from Stripe`
- `feat(web): add invoices page` (with README, screenshots)
- this plan → Done

## Follow-ups
- ~~The invoices throttle is per user only~~: done in plan 20 (`throttle:stripe`, per user and per organization).
- ~~Older README screenshots predate the "Billing" and "Invoices" sidebar items~~: retaken in plan 20.
