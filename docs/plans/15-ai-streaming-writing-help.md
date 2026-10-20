# 15 — Streaming foundation + AI writing help

**Status:** Done
**Commits:** `f1d3e1d` (api), `b556ebd` (web), `3e5b71b` (claude)

Part of the AI improvements series (plans 13–16), built and shipped in order.

## Context
Projects have one AI feature today (plan 09): a queued summary that a user approves. You asked for four improvements: **usage page + budget**, **better summaries**, **AI writing help** and **project chat**. Each one is a sizeable feature, so they become four plans, built and shipped one at a time (`/ship-plan` after each). The order puts cost controls first, then builds the streaming foundation on the smaller feature before chat uses it.

Decisions made:
- Budget: `.env` default plus a per-organization override. Only Owner can change it by default, through a new permission.
- Chat history is saved per user per project, and only that user sees it.
- Writing help streams like chat. CLAUDE.md changes to "interactive AI (chat, writing help) streams; background work is queued".

## Design
**Streaming in the AI layer:**
- Add `stream(AiRequest, Closure $onText): AiResponse` to `AiClient`.
- `AnthropicClient` sends `stream: true` with `withOptions(['stream' => true])`. It reads the PSR body line by line and parses the SSE events:
  - `message_start`: input tokens;
  - `content_block_delta`/`text_delta`: calls `$onText`;
  - `message_delta`: stop reason and output tokens;
  - `error`.
  The existing status and error mapping is reused.
- `FakeAiClient::pushStream(chunks)`. Recording and budget wrap `stream()` the same way. A stream cut off part-way is recorded with the tokens reported so far.
- Config `AI_STREAM_TIMEOUT=120`.

**HTTP:**
- `response()->eventStream()` (Laravel 13) with events `delta {text}`, `done {model}` and `error {message}`, and the header `X-Accel-Buffering: no`.
- `php artisan serve` handles one request at a time, so add `PHP_CLI_SERVER_WORKERS=4` to `.env.example` (comment), the README run steps and `servers.sh`.

**Web:**
- `useApiStream()` lives next to `useApi` in `composables/useApi.ts`. It sends the same bearer token and handles a 401 the same way, using `fetch` + `ReadableStream`, because `EventSource` can't send headers. It returns a reactive `{text, status, error, abort()}`.
- CLAUDE.md: API calls go through `useApi`/`useApiStream`, and the streaming rule from the Context section above.

**Writing help:**
- `POST /api/projects/writing-help` (policy: `ai.use` + `projects.create`) and `POST /api/projects/{project}/writing-help` (`ai.use` + project update). Both use `throttle:ai`, the budget check, and Form Request `{name?, description?, mode: improve|expand|shorten}` with length limits.
- `App\Ai\Prompts\ProjectWritingPrompt`:
  - the form text is wrapped in `<draft>` tags and treated as data;
  - the output is plain text, the description only, held to the project description length;
  - feature `project_writing`.
- `ProjectForm.vue`: an "Improve with AI" `UDropdownMenu` (Improve / Expand / Shorten) under Description streams into a preview card with Accept (replaces the field), Try again and Discard. Nothing is saved until the form is submitted, so it stays a draft the user approves.
- **Tests:**
  - stream parsing with `Http::fake` (SSE body), including an error event part-way;
  - recording and budget on streams;
  - the endpoint returns `text/event-stream` with delta and done events;
  - policy table;
  - 429 and 402;
  - the prompt contains only the submitted text;
  - org B's project gives 404.

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

## As built
Changes from the design above:
- The HTTP side uses `response()->stream()` in `App\Http\Responses\AiEventStream`, not `eventStream()`: that needs a generator, and `AiClient::stream()` reports text through a callback. Same events and headers. The callback returns false once the browser disconnects (`ignore_user_abort` + `connection_aborted()`), so the provider call stops and is still recorded.
- A stopped or broken-off stream estimates output tokens from the text (about 4 characters per token), since the provider only reports them at the end; otherwise stopping right away would count as nearly free against the budget. `AiUnavailable` now carries the partial response too.
- `PHP_CLI_SERVER_WORKERS` only works with `php artisan serve --no-reload`, so the README run step is `PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload`, and `.env.example` keeps the key commented with that note. `servers.sh` passes it to `php -S`.
- `useApiStream()` returns `{text, status, error, start, stop, reset}`; status is `idle|streaming|done|error|stopped`.
- Policy: `ProjectPolicy::writeWithAi(user, ?project)`. Mode enum `App\Enums\WritingHelpMode`. Expand works from the name alone; improve and shorten need a description.
- An answer longer than 5000 characters ends with an `error` event instead of `done`.
- UI: `ProjectWritingHelp.vue` inside `ProjectForm.vue`.
- After review:
  - At most `ai.concurrent_streams` (2) answers stream at once per organization (`App\Ai\AiStreamSlots`, cache locks; 429 when full), since each holds a PHP worker and the per-minute limit doesn't bound that.
  - `AnthropicClient` enforces `AI_STREAM_TIMEOUT` as a total deadline (Guzzle's timeout only limits each read when streaming).
  - `AiEventStream` runs as the organization that authorized it (`CurrentOrganization::runAs`) and releases its slot at the end.
  - Stopped streams are recorded as `succeeded` with `error_code = aborted`.
  - The UI offers Accept only for a non-empty answer; a stopped or replaced stream never shows a late error.

## Follow-ups
- `POST /api/organizations` has no throttle, so one user could make many organizations, each with its own budget and stream slots (security review).
- The total stream deadline in `AnthropicClient` has no test (it uses wall-clock time).
