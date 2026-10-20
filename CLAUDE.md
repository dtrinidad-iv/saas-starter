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

## Frontend conventions
- Use `<script setup lang="ts">` and Nuxt UI components before writing custom ones.
- API calls go through `useApi` (JSON) or `useApiStream` (server-sent events), both in `composables/useApi.ts`; they attach the auth token and handle a 401.
- Current user and current organization live in Pinia stores.
- The main layout is a collapsible sidebar (`UDashboardSidebar`): app name at the top, then the org switcher and navigation; the footer has the account menu (appearance, log out) and app version. The top bar (`UDashboardNavbar`) has the sidebar toggle/collapse button and the account menu on the right.

## AI layer conventions
- All AI calls happen in Laravel. Never expose provider keys to Nuxt.
- Code against the `AiClient` interface (`app/Ai`). Provider and model come from `config/ai.php` and `.env`. The container wraps the provider in `RecordingAiClient`, so `ai_usage` is written for you, and in `BudgetedAiClient`, which refuses calls once the organization's monthly budget is used up (`AiBudgetExceeded`: 402 in requests, a failed result in jobs). Check `AiBudget` before starting AI work so nothing is created that can't run.
- Slow work (summaries, bulk generation) runs as queued jobs with retries, backoff, and timeouts. Tenant work extends `App\Jobs\OrganizationJob` (sets the current organization); AI jobs add `RateLimited('ai')`.
- Interactive AI (chat, writing help) streams as SSE: `AiClient::stream()` returned as `App\Http\Responses\AiEventStream` (events `delta`, `done`, `error`). Its status is always 200 once it starts, so check the policy, validation and `AiBudget`, and take an `AiStreamSlots` slot (concurrent streams per organization), before returning it. Background work is queued.
- Record every call in `ai_usage` (organization_id, user_id, feature, model, tokens in/out, cost).
- Rate limit AI endpoints per organization. User-triggered AI needs the `ai.use` permission in its policy.
- Prompts live in dedicated classes or files under `app/Ai/Prompts`, not inline strings.
- Ask for structured JSON output and validate it before saving.
- AI output is saved as a draft that a user approves. It must never trigger destructive actions on its own.
- Treat all user content as untrusted (prompt injection). Never include another organization's data in a prompt.
- In tests, fake the AI client. Never make real API calls in tests. `TestCase` binds `FakeAiClient` as `$this->ai` (push responses, `assertSent`) and blocks stray HTTP. `AI_PROVIDER=fake` does the same for local and e2e runs.

## Audit log conventions
- Tenant-owned models that users create, edit or delete use the `Auditable` trait (diffs of changed columns; keys, timestamps and `$hidden` are never logged; add others to `$auditExclude`).
- Other notable actions (members, invitations, AI reviews) call `App\Actions\Audit\RecordAudit` inside their transaction. Pass `organization:` when not acting in it as the current organization (joining, creating).
- Every new event is an `App\Enums\AuditAction` case with a label. Never log secrets, tokens or passwords.
- Entries about projects use subject type `project` and keep project content out of `metadata`: `AuditLogResource` hides their label and changes from viewers without `projects.view`.
- Entries are append-only; `model:prune` (scheduled daily) deletes them after `AUDIT_RETENTION_DAYS`.

## Automation conventions
- Recurring work uses the Laravel Scheduler.
- Domain events (e.g. `ProjectCreated`) dispatch jobs. Keep listeners small.
- Per-organization webhooks: sign outgoing payloads (HMAC) and verify incoming ones.

## Claude Code tooling (`.claude/`, `.mcp.json`)
- Skills:
  - `/plan-task` (write a plan and stop for approval)
  - `/verify` (Pint, Pest, lint, typecheck, build)
  - `/tenant-feature` (recipe for adding a tenant-owned feature)
  - `/e2e-smoke` (real servers on :8011/:3011)
  - `/ship-plan` (commit, push and mark the plan Done; only you can start it)
- Subagents:
  - Read-only reviewers: `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer`, `database-reviewer`, and `ui-reviewer` (browser; runs on Sonnet in quick mode, checking only the items listed, unless asked for a "full sweep").
  - `laravel-test-writer` can edit only `api/tests/`, enforced by a hook.
- Before reporting a change as done, run the reviewers that fit, in parallel:
  - always code, security and tenant isolation;
  - database when there are migrations or queries;
  - UI when pages change.
- MCP: `playwright` (headless browser for e2e and UI review), plus `nuxt-ui` and `nuxt` (docs). Check the Nuxt UI MCP before guessing component props.

## Workflow rules
- Plan first for anything touching more than 3 files, then wait for approval.
- Plans live in `docs/plans/NN-task-name.md` (named after the task) with a Status line: Proposed → WIP (on approval) → Done (when committed, with commit hashes). Keep the index in `docs/plans/README.md` in sync.
- Build in small steps: one feature, tests passing, then commit.
- Docs change in the same commit as the code they describe: `.env.example` (new keys), `CLAUDE.md` (conventions, commands, tooling), root `README.md` (setup, run steps, features). `/ship-plan` checks this.
- Commit message format: `type(scope): summary` (e.g. `feat(api): add organization switch endpoint`).
- Ask before adding a new dependency and say why it's needed.
