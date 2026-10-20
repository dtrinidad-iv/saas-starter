# 16 — Project chat

**Status:** Proposed

Part of the AI improvements series (plans 13–16), built and shipped in order.

## Context
Projects have one AI feature today (plan 09): a queued summary that a user approves. You asked for four improvements: **usage page + budget**, **better summaries**, **AI writing help** and **project chat**. Each one is a sizeable feature, so they become four plans, built and shipped one at a time (`/ship-plan` after each). The order puts cost controls first, then builds the streaming foundation on the smaller feature before chat uses it.

Decisions made:
- Budget: `.env` default plus a per-organization override. Only Owner can change it by default, through a new permission.
- Chat history is saved per user per project, and only that user sees it.
- Writing help streams like chat. CLAUDE.md changes to "interactive AI (chat, writing help) streams; background work is queued".

## Design
- **Table `project_chat_messages`:**
  - columns: organization_id (cascade), project_id (cascade), user_id (cascade), role `user|assistant`, content text, model nullable, status `complete|failed|stopped`, timestamps;
  - index `(project_id, user_id, id)`;
  - `BelongsToOrganization`, not `Auditable` (personal and high churn).
- **Endpoints** (`ai.use` + `projects.view`; only your own messages):
  - `GET /api/projects/{project}/chat`: the last 50 messages.
  - `POST /api/projects/{project}/chat`: `{message ≤ 2000}`, `throttle:ai`, budget, then SSE. It saves the user message first, then saves the assistant message at the end. On disconnect (`connection_aborted()`) it saves the partial answer as `stopped`, and on error as `failed`.
  - `DELETE /api/projects/{project}/chat`: clears your conversation.
  - Actions: `SendProjectChatMessage`, `ClearProjectChat`.
- **`ProjectChatPrompt`:**
  - the system prompt holds the project's name, description and approved summary in `<project>` tags;
  - the last 20 messages are sent as history;
  - user messages are untrusted;
  - it answers about this project only and can't change anything (no tools);
  - feature `project_chat`.
- **Web:** `components/ProjectChat.vue` in a `USlideover` opened from an "Ask AI" button on the project page. It uses Nuxt UI's chat components (`UChatMessages`, `UChatPrompt`; check the props through the MCP) and `useApiStream`, with Stop, Clear and empty/error states. Switching organization closes it, since the page already redirects.
- **Tests:**
  - history is limited to 20;
  - partial and failed saves;
  - the user's messages are private (another member of the same organization sees none);
  - permission table;
  - throttle and budget;
  - org A vs B: 404, and B's messages are never in A's prompt (`assertSent`).

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
