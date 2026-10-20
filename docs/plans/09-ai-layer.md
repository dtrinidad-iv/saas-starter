# 09 — AI layer and project summaries

**Status:** Done
**Commits:** `6ee6c06` (api), `af31425` (web)

## Goal
Add the AI layer that CLAUDE.md describes, plus one real feature that uses it end to end. The layer is an `AiClient` interface, a Claude provider built on Laravel's HTTP client, a fake for tests, an `ai_usage` record of every call, a rate limit per organization, and a base class for queued jobs that run inside one organization. The feature is AI project summaries. When a project with a description is created (or someone clicks "Regenerate"), a queued job asks Claude for a summary and key points as JSON and saves them as a **draft**. A user then approves or rejects the draft on the project page. Approving only marks the summary as approved and shows it on the page; it never changes the project.

**Out of scope:** SSE chat streaming (no chat yet), a usage or cost dashboard (the data is recorded, the page comes later), monthly budgets per organization, other providers (the interface allows them), summaries for existing projects (no backfill command), and editing a draft before approving it.

## Decisions
- **Laravel HTTP client, no new dependency** (your choice). `Http::fake()` covers provider tests.
- **Model from config:** `AI_MODEL=claude-haiku-5-5` (your choice). Summaries are a simple task and this is a test project, so the cheapest current model fits. Switching to `claude-opus-5-5` or `claude-sonnet-5-5` is one `.env` change.
- **Structured output** through `output_config.format` (JSON schema) on `POST /v1/messages`. We still validate the JSON with Laravel's Validator before saving, as CLAUDE.md requires.
- **Refusal fallback off by default.** Claude Haiku 5.5 doesn't support the server-side fallback, so `AI_FALLBACKS` is empty and a refusal marks the summary `failed`. If you switch to Opus 5.5 or Sonnet 5.5, set `AI_FALLBACKS=default`. The client then sends `fallbacks: "default"` (beta header `server-side-fallback-2026-07-01`), but only for models listed in `config/ai.php` → `fallback_models`. Either way we record the model that actually answered (`response.model`) in `ai_usage` and price that model.
- **Every call is recorded, including failures,** by a `RecordingAiClient` decorator that wraps the provider. Features can't forget to log. Cost is computed from a price table in `config/ai.php` (USD per 1M tokens) and stored as `decimal(12,6)`.
- **Timeouts are nested:** HTTP timeout 60s < job `timeout` 75s < database queue `retry_after` 90s (the current default). This keeps a slow call from being picked up twice.
- **Retries:** 429, 5xx, timeouts and connection errors throw `AiUnavailable`, which is retried (3 exceptions max, backoff 10s / 60s / 300s). 4xx errors, refusals, `max_tokens` cut-offs and invalid JSON throw `AiRequestFailed`, which fails the job right away, because retrying won't help.
- **Rate limit per organization:** one named limiter, `ai`, keyed by the organization id. It's used by the `throttle:ai` route middleware (the user's current organization) and by a `RateLimited` job middleware (the organization stored on the job). Default `AI_RATE_LIMIT_PER_MINUTE=5` (your choice, low for a test project).
- **One summary row per generation.** The page shows the latest one. Requesting a summary while one is already pending returns that pending one instead of starting another.
- **Prompt injection:** the prompt class wraps the project's name and description in `<project>` tags. The system prompt says the tagged content is data to summarize, not instructions to follow. Only this one project's fields go into the prompt.

## Backend (`api/`)
1. **Config** `config/ai.php`: `provider` (`anthropic` | `fake`), `model`, `timeout`, `max_tokens`, `fallbacks`, `fallback_models` (opus-5-5, sonnet-5-5), `rate_limit_per_minute`, `providers.anthropic` (`api_key`, `base_url`, `version` `2023-06-01`), `pricing` (USD per 1M tokens: opus-5-5 4/20, sonnet-5-5 2/10, haiku-5-5 0.10/0.50). An unknown model is recorded with `cost = null` and logs a warning.
2. **`.env.example`** (with comments): `AI_PROVIDER=anthropic`, `ANTHROPIC_API_KEY=`, `AI_MODEL=claude-haiku-5-5`, `AI_TIMEOUT=60`, `AI_FALLBACKS=` (empty; `default` for Opus/Sonnet), `AI_RATE_LIMIT_PER_MINUTE=5`.
3. **`app/Ai/`**
   - `Contracts/AiClient`: `generate(AiRequest $request): AiResponse`.
   - `AiRequest` (readonly): `feature`, `system`, `messages`, `?schema`, `maxTokens`, `?userId`.
   - `AiResponse` (readonly): `text`, `?json` (decoded), `model`, `inputTokens`, `outputTokens`, `stopReason`.
   - `Clients/AnthropicClient`: builds the request (`x-api-key`, `anthropic-version`, beta header, `model`, `max_tokens`, `system`, `messages`, `output_config.format`, `fallbacks`). It reads the first `text` block and `usage`, and checks `stop_reason` before reading the content. It maps errors as described above.
   - `Clients/FakeAiClient`: queued canned responses (`push()`), records the requests (`assertSent(fn)`, `assertNothingSent()`), and can throw a given exception.
   - `Clients/RecordingAiClient`: wraps the real client and writes one `AiUsage` row per call (`status` `succeeded` | `failed`, plus `error_code`).
   - `Exceptions/AiUnavailable`, `Exceptions/AiRequestFailed`.
   - `Prompts/ProjectSummaryPrompt`: system text, the JSON schema (`summary` string up to 600 characters, `key_points` array of 0–5 strings up to 160 characters each), and a `request(Project, User)` method that builds the `AiRequest`.
   - Binding in `AppServiceProvider`: `AiClient` → `RecordingAiClient(provider from config)`. The `ai` rate limiter is registered there too.
4. **Migration `ai_usage`:** id, organization_id (cascade), user_id (nullable, null on delete), feature, model, input_tokens, output_tokens, cost_usd (`decimal(12,6)`, nullable), status, error_code (nullable), created_at. Index (organization_id, created_at). **Model `AiUsage`** uses `BelongsToOrganization` and is write-only for now.
5. **Migration `project_summaries`:** id, organization_id (cascade), project_id (cascade), status (`pending` | `draft` | `approved` | `rejected` | `failed`), summary (text, nullable), key_points (jsonb, nullable), model (nullable), error (nullable), requested_by and reviewed_by (users, null on delete), reviewed_at, timestamps. Index (organization_id, project_id, created_at). **Model `ProjectSummary`** uses `BelongsToOrganization`, casts `status` to an enum `ProjectSummaryStatus` and `key_points` to an array. Adds `Project::summaries()` and `latestSummary()` (`latestOfMany`).
6. **Job base** `app/Jobs/OrganizationJob` (abstract, `ShouldQueue`): stores `organizationId` and a nullable `userId`. Defaults are `tries = 10` with `maxExceptions = 3` (rate-limited releases count as attempts), `backoff = [10, 60, 300]`, `timeout = 75`, `failOnTimeout = true`. Its `middleware()` returns `SetCurrentOrganization` (loads the organization, deletes the job if the organization is gone, then calls `CurrentOrganization::set()`), followed by `RateLimited('ai')` for AI jobs. Update the `ProjectCreated` docblock to point to it.
7. **Actions**
   - `RequestProjectSummary(Project, User)`: returns the existing pending summary, or creates a `pending` row and dispatches `GenerateProjectSummary`. Used by the listener and by the regenerate endpoint.
   - `ReviewProjectSummary(ProjectSummary, User, approve|reject)`: only works on a `draft`, otherwise 409. Sets `status`, `reviewed_by` and `reviewed_at`. Approving marks older approved summaries as `rejected`, so only one approved summary exists per project.
8. **Job `GenerateProjectSummary extends OrganizationJob`:** loads the summary and its project through the organization scope (if either is gone, it does nothing). It calls `AiClient`, validates the JSON (`summary` required, string, max 600; `key_points` array, max 5, each string max 160), then saves it as `draft` along with the model. On `AiRequestFailed` or invalid JSON it sets `failed` with a short error and calls `$this->fail()`. Its `failed()` hook covers exhausted retries.
9. **Listener `QueueProjectSummary`** on `ProjectCreated`: if the description isn't blank, it calls `RequestProjectSummary`. It runs synchronously (it only writes a row and dispatches a job). The creator comes from `$project->creator`.
10. **Policy `ProjectSummaryPolicy`**: anyone in the organization can view. Request and review follow `ProjectPolicy::update` (owner and admin, or a member on a project they created).

    | Action | Owner | Admin | Member |
    |---|---|---|---|
    | View summary | ✓ | ✓ | ✓ |
    | Regenerate | ✓ | ✓ | own projects |
    | Approve / reject | ✓ | ✓ | own projects |

11. **Resource `ProjectSummaryResource`:** id, status, summary, key_points, model, error (only when failed, a generic message), requested_by and reviewed_by `{id, name}`, reviewed_at, created_at, and `can: {review, regenerate}`.
12. **Routes** (inside the `organization` group, with `scopeBindings()` so a summary must belong to the project):

    | Endpoint | Notes |
    |---|---|
    | `GET /api/projects/{project}/summary` | Latest summary, or `{data: null}` |
    | `POST /api/projects/{project}/summary` | `throttle:ai`. Returns 202 with the pending summary |
    | `POST /api/projects/{project}/summaries/{summary}/approve` | 409 unless it's a draft |
    | `POST /api/projects/{project}/summaries/{summary}/reject` | 409 unless it's a draft |

    Controller `ProjectSummaryController`, thin as usual. No form request is needed, since there's no input body.

## Frontend (`web/`)
13. **Types** `ProjectSummary` and `ProjectSummaryStatus` in `types/api.ts`.
14. **`components/ProjectSummaryCard.vue`** on `projects/[id].vue`, below the description. Its states:
    - **None:** "No summary yet" with a "Generate summary" button (if `can.regenerate`).
    - **Pending:** a skeleton and "Generating…". It polls `GET …/summary` every 3s, stops after 60s with a "Still working, check back soon" note, and stops when you leave the page.
    - **Draft:** an "AI draft" badge, the summary and key points, and Approve / Reject buttons (if `can.review`).
    - **Approved:** the summary with "Approved by X", plus Regenerate.
    - **Rejected / failed:** a short message and Regenerate.
    - A 429 shows a toast: "Too many AI requests for this organization, try again in a minute."
    - Switching organizations already redirects away from the page.
    - Nuxt UI components only (`UCard`, `UBadge`, `UButton`, `USkeleton`, `UAlert`). All calls go through `useApi`.

## Tests
- **`AnthropicClientTest`** (`Http::fake`): request shape (headers, model, schema; `fallbacks` sent only when configured and the model supports it), parsing text, JSON and usage, using `response.model` when a fallback answered, mapping 429/500/timeout → `AiUnavailable` and 400/refusal/`max_tokens` → `AiRequestFailed`.
- **`RecordingAiClientTest`:** a success writes `ai_usage` with tokens and the right cost. A failure writes a `failed` row and rethrows. An unknown model gives `cost = null`. The row's `organization_id` comes from `CurrentOrganization`.
- **`OrganizationJobTest`:** the middleware sets the current organization, so scoped queries inside the job only see that organization's rows. A deleted organization deletes the job.
- **`ProjectSummaryTest`:**
  - Creating a project with a description creates a pending summary and the job turns it into a draft.
  - A blank description creates nothing.
  - Invalid JSON (missing `summary`, too many key points) → `failed`, nothing saved.
  - Regenerate returns the pending summary instead of creating a second one.
  - Approve and reject only work on a draft (409 otherwise). Approving a new summary replaces the previous approved one.
  - The permission table, including a member on their own project vs. someone else's.
  - The rate limit: the 6th regenerate in a minute → 429, and another organization isn't affected.
- **Isolation (org A vs org B):** another organization's project or summary returns 404 for get, regenerate, approve and reject, and its row is unchanged. A summary id from org B under org A's project → 404. `ai_usage` rows land in the job's organization. The prompt contains only the target project's text (checked with `FakeAiClient::assertSent`).
- **Safety net:** `TestCase` binds `FakeAiClient` by default and calls `Http::preventStrayRequests()`, so no test can reach the real API.

## Verification
- `verify` skill: Pint, Pest, lint, typecheck, build.
- `e2e-smoke` on :8011/:3011 with `AI_PROVIDER=fake` for the servers, plus a queue worker: create a project → pending → draft → approve. Org B can't see the summary. Clean up the test data. One optional manual check against the real API, with your key, only if you want it.
- Reviewers in parallel: code, security (keys, prompt injection), tenant isolation, database (two migrations), and UI (the project page summary card).

## Changes during the build
- **Rate limit keys:** one named limiter, `ai`, with separate keys for UI requests (`request:{org}`) and provider calls (`job:{org}`), so one click isn't counted twice. Automatic summaries on project creation have their own per-organization cap (`ai-auto:{org}`, same limit); over it the project is created without a summary (security review: creating projects in a loop got around `throttle:ai`).
- **`GET …/summary` returns `{data, approved}`:** the latest summary of any status, plus the approved one. The card keeps showing the approved summary under a newer pending, draft, rejected or failed one (code review).
- **Requesting a summary** retires an unreviewed draft (it becomes `rejected`) and replaces a pending one older than 15 minutes (it becomes `failed`), so a lost job can't block the project.
- **Database:** partial unique indexes allow at most one `approved` and one `pending` summary per project. Reviews lock the project row first. Indexes added on the user foreign keys. The `(organization_id, project_id, created_at)` index was dropped (no query used it).
- **`CurrentOrganization::runAs()`:** jobs restore the previous organization afterwards instead of clearing it, which matters when a job runs synchronously.
- **`ai_usage.organization_id` cascades on delete,** like other organization-owned data. Revisit this if usage ever feeds billing.
- **Effort:** the summary prompt sends `effort: low` and `max_tokens: 2000`.

## Follow-ups (not done)
- **Budget:** a daily or monthly token/cost budget per organization, built on `ai_usage`, on top of the per-minute limit.
- **Failure reason:** a job that runs out of attempts only because it was rate limited is marked `unavailable`. Consider its own error code (`MaxAttemptsExceededException`).
- **Usage page:** a usage and cost page for owners, built from `ai_usage`.
- **Composer audit:** run `composer audit` again. It timed out during review. `npm audit` lists 12 advisories that predate this plan, all through Nuxt/Vite tooling. Watch for an upstream Nuxt patch.
- **Real API check:** one run against the real API with a key. Everything so far used the fake provider.

## Commits after approval
`feat(api): add AI client, usage tracking and project summaries`, `feat(web): show and review AI project summaries`, then docs (README features, `.env.example` already in the api commit, CLAUDE.md if a convention changed), then this plan → Done.
