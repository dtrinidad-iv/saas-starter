# Project: Multi-organization SaaS starter

## Stack
- `api/`: Laravel (latest stable), PostgreSQL, Sanctum (token auth), Pest
- `web/`: Nuxt 4 (code in `web/app/`), Nuxt UI, Pinia, TypeScript. Node 22.22.3+ (`web/.nvmrc`)
- Local: macOS, Postgres running locally, API on :8000, Nuxt on :3000

## Commands
- API: `cd api && PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload` (workers so a streamed AI answer doesn't block other requests), `php artisan migrate`, `php artisan test`
- Queue worker: `php artisan queue:work`
- Web: `cd web && npm run dev`, `npm run build`, `npm run lint`
- Always run `php artisan test` (api) and `npm run build` (web) before saying a task is done.
- Never wipe a database. Dev and test share one Postgres, and `--env=testing` without an `.env.testing` uses `.env`. `migrate:fresh`/`refresh`/`reset`/`rollback` and `db:wipe` refuse to run unless the database name ends in `_test` (`AppServiceProvider::guardDestructiveDatabaseCommands`; `DB_ALLOW_DESTRUCTIVE_COMMANDS=true` for one deliberate run by the user), and `.claude/hooks/block-destructive-db.sh` blocks them, `dropdb` and `DROP`/`TRUNCATE` for Claude. Pest rebuilds its own database.

## Multi-organization model
- Users belong to many organizations via `organization_user` pivot with `role_id`. Roles are per organization (`roles` table, tenant-owned) with permissions from `App\Enums\Permission`. New organizations get Owner, Admin and Member (`App\Enums\DefaultRole`); Owner is built in (`is_owner`, every permission, can't be edited or deleted).
- Policies check permissions (`CurrentOrganization::can()`), never role names. A non-owner only grants, assigns, revokes or changes roles they cover (`Role::isSubsetOf` / `isStrictSubsetOf`). To add a permission, add the enum case and decide whether `DefaultRole` grants it; existing organizations' roles only get it through a new migration.
- No tenant in the URL or subdomain. The active organization is stored server-side (`users.current_organization_id`).
- Switching: `POST /api/organizations/{id}/switch`. Verify membership on the server every time.
- Tenant-owned models use the `BelongsToOrganization` trait (global scope + auto-set `organization_id` on create).
- NEVER accept an organization id from the frontend as proof of access. Always derive it from the authenticated user.
- Every tenant-owned feature needs a Pest test proving org A cannot read or write org B's data.

## Backend conventions
- Thin controllers. Business logic in Action or Service classes.
- Use Form Requests for validation and API Resources for responses.
- Migrations only. Never edit an existing migration that has been committed; add a new one.
- Do not edit `.env`. Add new keys to `.env.example` with a comment.
- Features that reach other people or cost money (invitations, joining, billing, AI) use the `verified` middleware (403 `email_unverified`). Account endpoints (`/api/account*`) act on the user, never an organization.
- Tokens are only issued through `IssueToken`. With two-factor on (`User::hasTwoFactorEnabled`, TOTP in `App\Support\Totp`), login and password reset return `{ two_factor: true, challenge }` instead, and `POST /api/two-factor-challenge` swaps it plus a code for the token. A new way to log in must go through the same check.
- Email links point at Nuxt (`config('app.frontend_url')`), which posts them back to the API. The verification link is signed relative to the API (`signed:relative`). Each group of `throttle:6,1` routes has its own prefix (e.g. `throttle:6,1,account`) so they don't share a counter.

## Frontend conventions
- Use `<script setup lang="ts">` and Nuxt UI components before writing custom ones.
- API calls go through `useApi` (JSON) or `useApiStream` (server-sent events), both in `composables/useApi.ts`; they attach the auth token and handle a 401.
- Current user and current organization live in Pinia stores.
- The main layout is a collapsible sidebar (`UDashboardSidebar`): app name at the top, then the org switcher and navigation; the footer has the account menu (appearance, log out) and app version. The top bar (`UDashboardNavbar`) has the sidebar toggle/collapse button and the account menu on the right.

## AI layer conventions
- All AI calls happen in Laravel. Never expose provider keys to Nuxt.
- Code against the `AiClient` interface (`app/Ai`). Provider and model come from `config/ai.php` and `.env`. The container wraps the provider in `RecordingAiClient`, so `ai_usage` is written for you, and in `BudgetedAiClient`, which refuses calls once the organization's monthly budget is used up (`AiBudgetExceeded`: 402 in requests, a failed result in jobs). Check `AiBudget` before starting AI work so nothing is created that can't run.
- Slow work (summaries, bulk generation) runs as queued jobs with retries, backoff, and timeouts. Tenant work extends `App\Jobs\OrganizationJob` (sets the current organization); AI jobs add `RateLimited('ai')`.
- Interactive AI (chat, writing help) streams as SSE: `AiClient::stream()` returned as `App\Http\Responses\AiEventStream` (events `delta`, `done`, `error`). Its status is always 200 once it starts, so check the policy, validation and `AiBudget`, and take an `AiStreamSlots` slot (concurrent streams per organization), before returning it. To save the answer (e.g. chat messages), pass `onEnd`, which runs once with the text and outcome (done, error, stopped). Background work is queued.
- Record every call in `ai_usage` (organization_id, user_id, feature, model, tokens in/out, cost).
- Rate limit AI endpoints per organization. User-triggered AI needs the `ai.use` permission in its policy.
- Prompts live in dedicated classes or files under `app/Ai/Prompts`, not inline strings.
- Ask for structured JSON output and validate it before saving.
- AI output is saved as a draft that a user approves. It must never trigger destructive actions on its own.
- Treat all user content as untrusted (prompt injection). Never include another organization's data in a prompt.
- In tests, fake the AI client. Never make real API calls in tests. `TestCase` binds `FakeAiClient` as `$this->ai` (push responses and streams, `FakeAiClient::ABORT` to simulate the browser leaving, `assertSent`) and blocks stray HTTP. `AI_PROVIDER=fake` does the same for local and e2e runs.

## Billing conventions
- Stripe through Laravel Cashier. The **organization** is the billable (`Cashier::useCustomerModel`); billing endpoints act on the current organization and need `billing.manage`.
- Code against `App\Billing\Contracts\BillingGateway` (`StripeBillingGateway`, or `FakeBillingGateway` with `BILLING_PROVIDER=fake`). Payments happen on Stripe's hosted pages (Checkout, Customer Portal); never handle card data. Tests use `$this->billing` (the fake) and never call Stripe.
- Routes that call Stripe use `throttle:stripe` (`AppServiceProvider`): 20 a minute per user, and 60 per organization counted only for people with `billing.manage`, so others' 403s can't lock owners out.
- Invoices are read live from Stripe (`BillingGateway::invoices`), never stored. Only `https` links on `stripe.com` hosts are passed to the client. In tests, `$this->billing->setInvoices()`; with `BILLING_PROVIDER=fake`, demo invoices come from the organization's subscriptions and credit purchases.
- Plans live in `config/billing.php`; `App\Billing\Plans::for()` says which one an organization is on. The client sends a plan key, never a price id or amount.
- Only the webhook (`POST /api/stripe/webhook`, `StripeWebhookController`) changes subscriptions; success redirects only refetch. `VerifyStripeWebhook` always checks the signature and refuses everything without `STRIPE_WEBHOOK_SECRET`. Make webhook handling idempotent (Stripe retries).
- Cashier's `subscriptions` table has `organization_id` but no global scope: reach it only through `$organization->subscriptions()` (or `Plans`), never `Subscription::query()`.
- Plan changes are audited (`PlanChanged`) when Stripe reports them (`Plans::reportedFor`), not when an end date passes.
- The plan's AI budget is a ceiling: `organizations.ai_monthly_budget_usd` can only lower it. AI credits (`ai_credit_purchases`, packs in `config/billing.php`) add to the month they're bought in; `GrantAiCredits` takes the amount from config, never from the event, and grants once per Checkout session.
- In tests, `subscribe()` writes a subscription directly and `stripeWebhook()` posts a signed event (`tests/Pest.php`).

## Audit log conventions
- Tenant-owned models that users create, edit or delete use the `Auditable` trait (diffs of changed columns; keys, timestamps and `$hidden` are never logged; add others to `$auditExclude`).
- Other notable actions (members, invitations, AI reviews) call `App\Actions\Audit\RecordAudit` inside their transaction. Pass `organization:` when not acting in it as the current organization (joining, creating).
- Every new event is an `App\Enums\AuditAction` case with a label. Never log secrets, tokens or passwords.
- Entries about projects use subject type `project` and keep project content out of `metadata`: `AuditLogResource` hides their label and changes from viewers without `projects.view`.
- Entries are append-only; `model:prune` (scheduled daily) deletes them after `AUDIT_RETENTION_DAYS`.

## Platform admin conventions
- Platform admins run the app; it's not an organization role. `users.is_platform_admin` is not fillable and changes only through `php artisan admin:grant` (refused without 2FA) / `admin:revoke`, never an API request. Turning 2FA off revokes it (`DisableTwoFactor`, logged).
- Admin routes (`/api/admin/*`) sit outside the `organization` group behind `platform-admin` (`EnsurePlatformAdmin`): 404 for non-admins, 403 `two_factor_required` without 2FA, 403 `two_factor_reauth` unless this token's `two_factor_at` is within `config('admin.two_factor_ttl_minutes')`. Tokens get `two_factor_at` only from a code of the existing factor: the login challenge with an app code (`IssueToken(..., twoFactor: true)`; not a recovery code) or `POST /api/account/two-factor/reconfirm`. Confirming a new setup doesn't count. `EnsurePlatformAdmin` runs before route bindings and answers with the router's own 404, and the `admin` limiter skips non-admins, so nothing reveals the area. A wrong method (405) or a plain OPTIONS on `api/admin/*` gets that 404 too (`bootstrap/app.php`, `routes/api.php`).
- Cross-organization reads and writes live only in `app/Actions/Admin`, with explicit `withoutGlobalScope(OrganizationScope::class)->where('organization_id', …)` (or `Membership::loadRoles`). They never set `CurrentOrganization` (the one exception: `RecordAudit`'s `organization:` runs as it just for the insert) or change the admin's membership, and never read project content or chats.
- Admin actions take a `reason` (`AdminActionRequest`, kept in the platform log only), sit behind `platform-admin:action` (a code within `config('admin.action_two_factor_ttl_minutes')`), and never act on a platform admin, yourself included (`ActsOnUsers`; Make Owner refuses admins as the member too). A change inside an organization is also written to its audit log with `RecordAudit(..., byPlatformAdmin: true)`: no actor or IP, shown as "Platform admin".
- A suspended user (`users.suspended_at`, `User::isSuspended`) is refused by login, the two-factor challenge, password reset (no link is sent) and `Sanctum::authenticateAccessTokensUsing`. A new way to log in or get a token must check it too.
- Responses use `App\Http\Resources\Admin\*`, which list every field; don't reuse `UserResource`/`OrganizationResource` there.
- What admins look at or change goes in the platform log (`RecordPlatformAudit`, `App\Enums\PlatformAuditAction`), not an organization's audit log. Detail views are logged; list searches aren't (they hold emails).
- Tests send a real token from `platformAdmin()` / `adminToken()` (`tests/Pest.php`), since `Sanctum::actingAs` has no `two_factor_at`.

## Automation conventions
- Recurring work uses the Laravel Scheduler.
- Domain events (e.g. `ProjectCreated`) dispatch jobs. Keep listeners small.
- Per-organization webhooks: sign outgoing payloads (HMAC) and verify incoming ones.

## Claude Code tooling (`.claude/`, `.mcp.json`)
- Skills:
  - `/plan-task` (write a plan and stop for approval)
  - `/verify` (Pint, Pest, lint, typecheck, build)
  - `/tenant-feature` (recipe for adding a tenant-owned feature)
  - `/e2e-smoke` (real servers on :8011/:3011; `start --mail` sends emails to the log at once, `mail.sh` reads them and their links)
  - `/ship-plan` (commit, push and mark the plan Done; only you can start it)
- Subagents:
  - Read-only reviewers: `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer`, `database-reviewer`, and `ui-reviewer` (browser; runs on Sonnet in quick mode, checking only the items listed, unless asked for a "full sweep").
  - `flow-tester` (browser + curl; Sonnet) runs a plan's Flows end to end, emails included, and returns a results table. It doesn't edit code, uses only `e2e-ft-*` accounts and removes them; a hook limits its `servers.sh` use to `status`, `errors` and `cleanup e2e-ft-`.
  - `laravel-test-writer` can edit only `api/tests/`, enforced by a hook.
- Hooks: `block-destructive-db.sh` (every Bash call, subagents included) refuses commands that wipe or drop database data.
- Before reporting a change as done, run the reviewers that fit, in parallel:
  - always code, security and tenant isolation;
  - database when there are migrations or queries;
  - UI when pages change.
- After the tests pass, when the plan has Flows, start the servers with `--mail` and run `flow-tester` on them (in the background, alongside `ui-reviewer` if it runs).
- MCP: `playwright` (headless browser for e2e and UI review), plus `nuxt-ui` and `nuxt` (docs). Check the Nuxt UI MCP before guessing component props.

## Workflow rules
- Plan first for anything touching more than 3 files, then wait for approval.
- Plans live in `docs/plans/NN-task-name.md` (named after the task) with a Status line: Proposed → WIP (on approval) → Done (when committed, with commit hashes). Keep the index in `docs/plans/README.md` in sync.
- Build in small steps: one feature, tests passing, then commit.
- Docs change in the same commit as the code they describe: `.env.example` (new keys), `CLAUDE.md` (conventions, commands, tooling), root `README.md` (setup, run steps, features). `/ship-plan` checks this.
- A new page, or a user-visible feature worth showing, gets a README screenshot in the same plan: a PNG in `docs/screenshots/` plus a line in the README "Screenshots" section, captured with the Playwright MCP from e2e demo data the way plan 17 did (light mode, 1280×800, `e2e-*` accounts cleaned up). Retake an existing shot when a change makes it out of date.
- Commit message format: `type(scope): summary` (e.g. `feat(api): add organization switch endpoint`).
- Ask before adding a new dependency and say why it's needed.
