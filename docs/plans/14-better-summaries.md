# 14 — Better summaries

**Status:** Done
**Commits:** `d8c5764` (api), `32f5b05` (web)

Part of the AI improvements series (plans 13–16), built and shipped in order.

## Context
Projects have one AI feature today (plan 09): a queued summary that a user approves. You asked for four improvements: **usage page + budget**, **better summaries**, **AI writing help** and **project chat**. Each one is a sizeable feature, so they become four plans, built and shipped one at a time (`/ship-plan` after each). The order puts cost controls first, then builds the streaming foundation on the smaller feature before chat uses it.

Decisions made:
- Budget: `.env` default plus a per-organization override. Only Owner can change it by default, through a new permission.
- Chat history is saved per user per project, and only that user sees it.
- Writing help streams like chat. CLAUDE.md changes to "interactive AI (chat, writing help) streams; background work is queued".

## Design
- **Stale flag:** a migration adds `project_summaries.source_hash`, the sha256 of name + description, set by `RequestProjectSummary`. `ProjectSummaryResource` adds `stale` when the project's current hash differs. The card shows "The project changed since this summary" with Regenerate. Nothing is regenerated automatically (cost).
- **Edit before approve:** `POST …/summaries/{summary}/approve` takes optional `summary` and `key_points`. A new `ApproveProjectSummaryRequest` reuses `ProjectSummaryPrompt::rules()`. `ReviewProjectSummary` saves the edits, and the audit `summary.approved` metadata gets `edited: true` (no project content in the metadata). The draft card gets an Edit mode: textarea plus key point inputs.
- **Backfill:** `php artisan ai:summarize-projects {--organization=*} {--limit=50} {--dry-run}`.
  - It runs each organization through `CurrentOrganization::runAs()`.
  - It picks projects with a description and no summary, and calls `RequestProjectSummary`, with the creator as the requester.
  - It stops for an organization when its budget is used up, and prints counts.
- **Tests:**
  - stale on/off;
  - edited approve with validation (too many points gives 422);
  - edits only allowed on drafts;
  - backfill picks the right projects, respects `--limit`, the budget and dry-run, and never touches another organization's projects.

## Changes during the build
- **The hash is set by the job, not `RequestProjectSummary`.** It is the hash of the project as it was sent to the model. A project edited between the request and the job run then doesn't get a fresh summary flagged as stale (tested).
- **Backfill only for creators allowed to use AI** (security review). A project is summarized only if its creator is still a member whose role has `ai.use`, like the automatic summary on create. Otherwise an organization that took `ai.use` away from every role would still have its projects sent to the provider. Skipped projects are counted in the output.
- **The backfill's audit entry is a system one:** `summary.requested` has no actor and `metadata.source = "backfill"`. The creator is still `requested_by` and is billed in `ai_usage` (`RequestProjectSummary` takes an optional `source`).
- **Budget in the backfill:** it skips an organization whose budget is already spent, and stops queueing once `RequestProjectSummary` refuses. On a real queue nothing is spent until the jobs run, so summaries queued past the budget fail with `budget_exceeded`. `--limit` caps how many. The README and the command output say so.
- **Job's final write locks the row and saves only if it's still pending** (database review). Before, a job that outlived the 15-minute stale cutoff could turn its failed row back into a second draft.
- **Approve with edits:** `edited: true` is recorded only when a field really changed, compared field by field (so key order doesn't matter). Edits sent with a rejection are ignored.
- **Web:**
  - A stale draft also shows Regenerate, which retires the draft.
  - A failed save keeps the editor open (only 409/404 close it and reload), so edits aren't lost.
  - List-level 422s (`key_points`) show on the Key points group.
  - New `ProjectSummaryEditor.vue`.

## Follow-ups (not done)
- **Edited summaries still show the AI `model`.** Consider exposing `edited` on the summary, so human-edited text isn't presented as plain AI output.
- **422 before 403** on approve (the Form Request validates before the policy runs). Harmless; same as the other endpoints.
- **Backfill with no `--organization`** covers every organization (capped by `--limit`). Consider asking for confirmation in that case.

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
