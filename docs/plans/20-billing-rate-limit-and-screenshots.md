# 20 — Billing rate limit per organization, and screenshot refresh

**Status:** Done
**Commits:** `9d07d84` (api), `5900622` (docs)

## Goal
The two follow-ups left by plans 18 and 19:
1. **Stripe calls limited per organization, not only per user.** Today each billing route that calls Stripe has `throttle:N,1` keyed per user, so ten owners or finance members of one organization can make ten times the calls. Stripe's API rate limit is per account (the whole app), so one busy organization could slow billing for everyone.
2. **Retake the README screenshots that predate the "Billing" and "Invoices" sidebar items.** Every owner page shows those two items now, so the older shots no longer match the app.

Out of scope: rate limits on routes that don't call Stripe, the webhook's limit (`throttle:120,1`, Stripe → us), new screenshots, dark mode.

## Decisions
- **One named limiter, `stripe`, on every route that calls Stripe:** `GET /billing/invoices`, `POST /billing/checkout`, `POST /billing/credits/checkout`, `POST /billing/portal`. It returns two limits, and a request needs both:
  - per user: 20 a minute (same as the POSTs today; invoices goes from 30 to 20, still 400 invoices a minute with Load more);
  - per organization: 60 a minute.
  - Keyed on the current organization (the `organization` middleware runs first), like the `ai` limiter. `GET /api/billing` doesn't call Stripe and stays unthrottled.
  - Only people with `billing.manage` count toward the organization's 60 (found in review): the throttle runs before the policy, so otherwise a few Members' 403s could lock the owners out of billing for a minute. Everyone still has the per-user 20.
  - A 429 is JSON, like the AI limiter: "Too many billing requests. Try again in a minute." for the user limit, "... for this organization ..." for the organization limit.
  - Numbers hard-coded in `AppServiceProvider` next to `ai` (the other route throttles are inline too); no new env keys.
- **Screenshots retaken the plan-17 way:** same demo data and steps, e2e servers, light mode, 1280×800 (the chat one at 1280×1000), `e2e-*` accounts cleaned up. **Real Claude for the AI content**, as plan 17 did (Haiku, about 11 calls, under $0.01): the servers start without `AI_PROVIDER=fake` for that session only. `BILLING_PROVIDER=fake` so nothing touches Stripe.
  - Retaken (owner sidebar visible): `projects.png`, `project-summary.png`, `writing-help.png`, `project-chat.png`, `members.png`, `roles.png`, `role-permissions.png`, `audit-log.png`, `ai-usage.png`.
  - Not retaken: `mobile.png` (the sidebar is collapsed at 390px), `billing.png` and `invoices.png` (taken in plan 19).
  - README alt text and lines stay unless a shot's content changes.

## Backend (`api/`)
- `AppServiceProvider::boot`: `RateLimiter::for('stripe', ...)` returning `Limit::perMinute(20)->by('stripe:user:'.$id)`, plus `Limit::perMinute(60)->by('stripe:org:'.$orgId)` when there is a current organization and the user has `billing.manage`, each with its JSON 429 response.
- `routes/api.php`: the four routes use `throttle:stripe` in place of `throttle:20,1` / `throttle:30,1`.

## Frontend (`web/`)
None. The billing and invoices pages already show the API's error message in a toast or alert.

## Tests (`tests/Feature/Billing/BillingRateLimitTest.php`)
- One user: the 21st invoices request in a minute → 429 with the message; a new minute allows it again (`travel`).
- Three owners of one organization: together they get 60 requests, the 61st → 429 even though each one is under 20.
- **Org A vs B:** org A using up its 60 doesn't limit org B's owner; and a user in both organizations, blocked only by org A's limit, can still list invoices after switching to org B (their own per-user limit applies in both).
- Four Members' 403s (80 requests) don't use up the organization's limit; the owner still gets 200.
- The POSTs share the same limiter: checkout requests count toward the same per-user limit as invoices.

## README screenshots
Retake the nine listed above. No new files.

## Verification
`/verify` (Pint, Pest, lint, typecheck, build); reviewers on the code change: code, security, tenant isolation (no database or UI changes). Look at each image before committing, then `servers.sh cleanup` / `stop` and confirm no `e2e-*` users remain.

## Commits after approval
- `feat(api): limit Stripe calls per organization`
- `docs: retake README screenshots with the billing sidebar items`
- this plan → Done, and the follow-ups in plans 18 and 19 marked as done here.

## Notes from capturing
- Demo data as in plan 17 (Maya Chen, Sam Rivera joined through the invitation link, Contributor and Viewer roles, two pending invitations, five projects); servers started with `BILLING_PROVIDER=fake` only, so AI used the configured Anthropic model.
- 8 Claude calls (5 summaries, 1 writing help, 2 chat answers), $0.0013 in total.
- `role-permissions.png` now also shows "Manage the plan, payment details and invoices" under Organization.
