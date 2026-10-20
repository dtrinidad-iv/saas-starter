# Project: Multi-organization SaaS starter

## Stack
- `api/`: Laravel (latest stable), PostgreSQL, Sanctum (token auth), Pest
- `web/`: Nuxt 4 (code in `web/app/`), Nuxt UI, Pinia, TypeScript. Node 22.22.3+ (`web/.nvmrc`)
- Local: macOS, Postgres running locally, API on :8000, Nuxt on :3000

## Commands
- API: `cd api && php artisan serve`, `php artisan migrate`, `php artisan test`
- Queue worker: `php artisan queue:work`
- Web: `cd web && npm run dev`, `npm run build`, `npm run lint`
- Always run `php artisan test` (api) and `npm run build` (web) before saying a task is done.

## Multi-organization model
- Users belong to many organizations via `organization_user` pivot with `role` (owner, admin, member).
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
- API calls go through one composable (`useApi`) that attaches the auth token.
- Current user and current organization live in Pinia stores.
- The org switcher lives in the main layout header.

## AI layer conventions
- All AI calls happen in Laravel. Never expose provider keys to Nuxt.
- Code against the `AiClient` interface. Provider and model come from `config/ai.php` and `.env`.
- Slow work (summaries, bulk generation) runs as queued jobs with retries, backoff, and timeouts.
- Interactive chat uses SSE streaming. Everything else is queued.
- Record every call in `ai_usage` (organization_id, user_id, feature, model, tokens in/out, cost).
- Rate limit AI endpoints per organization.
- Prompts live in dedicated classes or files under `app/Ai/Prompts`, not inline strings.
- Ask for structured JSON output and validate it before saving.
- AI output is saved as a draft that a user approves. It must never trigger destructive actions on its own.
- Treat all user content as untrusted (prompt injection). Never include another organization's data in a prompt.
- In tests, fake the AI client. Never make real API calls in tests.

## Automation conventions
- Recurring work uses the Laravel Scheduler.
- Domain events (e.g. `ProjectCreated`) dispatch jobs. Keep listeners small.
- Per-organization webhooks: sign outgoing payloads (HMAC) and verify incoming ones.

## Workflow rules
- Plan first for anything touching more than 3 files, then wait for approval.
- Build in small steps: one feature, tests passing, then commit.
- Commit message format: `type(scope): summary` (e.g. `feat(api): add organization switch endpoint`).
- Ask before adding a new dependency and say why it's needed.
