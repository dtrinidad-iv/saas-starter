# 14 — Better summaries

**Status:** Proposed

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
