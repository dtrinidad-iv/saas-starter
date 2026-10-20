# 13 — AI usage, budget and an `ai.use` permission

**Status:** Done
**Commits:** `9c66ee6` (api), `8a8566c` (web)

Part of the AI improvements series (plans 13–16), built and shipped in order.

## Context
Projects have one AI feature today (plan 09): a queued summary that a user approves. You asked for four improvements: **usage page + budget**, **better summaries**, **AI writing help** and **project chat**. Each one is a sizeable feature, so they become four plans, built and shipped one at a time (`/ship-plan` after each). The order puts cost controls first, then builds the streaming foundation on the smaller feature before chat uses it.

Decisions made:
- Budget: `.env` default plus a per-organization override. Only Owner can change it by default, through a new permission.
- Chat history is saved per user per project, and only that user sees it.
- Writing help streams like chat. CLAUDE.md changes to "interactive AI (chat, writing help) streams; background work is queued".

## Design
**Permissions** (`App\Enums\Permission` and `DefaultRole`):
- `ai.use`: request summaries, writing help and chat. Admin and Member get it.
- `ai_usage.view`: Admin gets it.
- `ai_budget.manage`: no default role gets it, so only Owner has it.

One migration grants `ai.use` to existing Admin and Member roles and `ai_usage.view` to Admin, following `2026_10_20_160001_grant_audit_log_view_to_admin_roles.php`. `ProjectSummaryPolicy::create` also needs `ai.use`. Review still follows project update.

**Budget:**
- Migration `organizations.ai_monthly_budget_usd` `decimal(10,2)` nullable: null uses `config('ai.monthly_budget_usd')` (`AI_MONTHLY_BUDGET_USD=5` in `.env.example`), and 0 turns AI off.
- `App\Ai\AiBudget`: `spent(Organization)` sums `ai_usage.cost_usd` since the start of the UTC month, using the existing `(organization_id, created_at)` index. It also provides `limit()`, `remaining()` and `ensureAvailable()`.
- New decorator `BudgetedAiClient`, bound as `Budgeted(Recording(provider))`. It throws `AiBudgetExceeded` (extends `AiRequestFailed`, code `budget_exceeded`) before the call, so blocked calls aren't recorded. Concurrent calls can go slightly over the budget; that's accepted and documented.
- HTTP AI endpoints check the budget first and return **402** `{message, code: 'ai_budget_exceeded'}`. Summary jobs mark the summary failed, and the resource shows "This organization's monthly AI budget is used up."

**Usage page (API):**
- `GET /api/ai-usage?month=YYYY-MM` (`ai_usage.view`): totals (calls, tokens, cost, failed), by feature, by user (name), by day, and `budget {limit, spent, remaining, can_manage}`. Thin controller, `AiUsageReport` action, Form Request for `month`.
- `PUT /api/ai-budget` (`ai_budget.manage`, `{monthly_budget_usd: null|0–10000}`), action `UpdateAiBudget`, audit `AuditAction::AiBudgetUpdated` (old/new).

**Web:**
- `pages/ai-usage.vue`, with a nav item gated by `can('ai_usage.view')`:
  - budget `UProgress` card with an edit modal when `can_manage`;
  - stat cards;
  - `UTable`s by feature, user and day;
  - month picker.
- AI buttons are hidden without `can('ai.use')`. A 402 shows a toast.

**Tests:**
- permission table;
- budget math across a month boundary;
- over budget gives 402, the job marks the summary failed, and no provider call is made (`assertNothingSent`);
- null/0/override behaviour;
- audit entry;
- org A can't see B's usage or change B's budget, and B's spend doesn't count toward A.

## Changes during the build
- **Unpriced models count toward the budget** (security review). Before, a model missing from `config('ai.pricing')` was recorded with a null cost, which `SUM` skips, so the budget never saw it. Now a dated id such as `claude-haiku-5-5-20261001` uses its base model's price. Anything else uses the configured model's price, or the highest configured price. Cost is null only when no prices are configured at all.
- **Controllers take `CurrentOrganization` per action, not in the constructor** (found by the tests). The router reuses controller instances in long-lived processes (Octane, the test runner), so a constructor-held organization carried over to the next request. One organization's request could then read or write another's budget. Fixed in `AiUsageController` and in the older `MemberController`, which had the same pattern. Regression tests are in `AiUsageIsolationTest`.
- **Project creation never fails on the budget.** The automatic summary is skipped instead, through one budget check inside `RequestProjectSummary`.
- **A pending summary is still returned when the organization is over budget.** The budget is only checked when a new summary would start.
- **`PUT /api/ai-budget`** is throttled (`throttle:20,1`) and returns only the budget, built by `AiBudget::summary()`, which the report also uses.
- **A blank `AI_MONTHLY_BUDGET_USD=`** means the default. An explicit `0` still turns AI off.
- **The usage report** fully qualifies its columns (by-user joins `users`) and breaks ties by name.
- **Web:**
  - "Generate summary" (before a project has any summary) also needs `ai.use`.
  - Clearing the month field falls back to the current month.
  - Stat cards dim while another month loads.
- **Accepted:**
  - The permission-grant migration matches roles by name ("Admin", "Member"), like the audit-log one, so a custom role named "Admin" also gets the grants. This stays within its organization.
  - Calls running at the same time can go slightly over the budget.

## Follow-ups (not done)
- **Progress bar label:** the budget bar's own accessible name is only the percentage (the full sentence is on its wrapper). Consider passing a clearer label through to the `role=progressbar` element.
- **Refused calls are not in the report:** blocked calls never reach `ai_usage`, so the page doesn't show how many requests the budget refused.
- **Scale:** if one organization reaches tens of thousands of AI calls a month, add `ai_usage (organization_id, created_at) INCLUDE (cost_usd)` in a new migration so the per-call budget sum is an index-only scan.
- **422 before 403:** both endpoints validate input before checking the permission, so someone without access sees a 422 for a bad month instead of a 403. Harmless; matches the audit log.

## Reuse
- `RecordingAiClient` decorator pattern and container binding: `app/Providers/AppServiceProvider.php`
- `ai` rate limiter (same file), `ProjectSummaryPrompt` (tags and rules pattern), `RequestProjectSummary` and `ReviewProjectSummary`, `GenerateProjectSummary`
- `RecordAudit`, the `CurrentOrganization::runAs()` and `can()` helpers, the permission-grant migration pattern
- `useOrganizationPermissions().can`, `ProjectSummaryCard.vue` (states and toasts), `ProjectForm.vue`, the layout's `navigation` list

## Verification (each plan)
- `/verify` (Pint, Pest, lint, typecheck, build).
- `/e2e-smoke` on :8011/:3011 with `AI_PROVIDER=fake`, a queue worker and `PHP_CLI_SERVER_WORKERS` from plan 15. For each feature: the happy path, permission 403s, budget 402, and org B getting 404. For plans 15 and 16, watch the stream arrive in the browser.
- Reviewers in parallel: code, security (prompt injection, budget bypass, SSE auth), tenant isolation, database (13, 14 and 16 add migrations), and UI (pages and the chat panel).
- An optional real-API check with your key at the end of 15 or 16, which is the first real streaming call.
- Docs in the same commits: `.env.example` (budget, stream timeout, workers), CLAUDE.md (streaming and `useApiStream` rules, new permissions), README (features, run steps).
