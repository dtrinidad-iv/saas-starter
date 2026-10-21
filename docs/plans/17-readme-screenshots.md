# 17 — README screenshots

**Status:** Done
**Commits:** `aff414d` (docs)

## Goal
Show what the app does at a glance: a Screenshots section in the root README with captures of the main pages, made from realistic demo data and real Claude answers. Out of scope: an automated, re-runnable screenshot script (it would need `@playwright/test` as a new dependency), dark-mode shots, and any app code changes.

## Decisions
- **Real Claude for AI content** (your choice): the session runs with `ANTHROPIC_API_KEY` from `.env` and the configured model (Haiku), about 6–8 calls, well under $0.05. The servers are started without `AI_PROVIDER=fake` for this session only; nothing about the provider setup changes.
- **Light mode only** (your choice), desktop at 1280×800 plus one 390px mobile shot.
- **Captured once with the Playwright MCP**, the browser tooling we already have. No new dependency. Re-taking them later means repeating these steps; the plan lists them so that's easy.
- **Images in `docs/screenshots/`** as PNG, committed (`.playwright-mcp/` stays ignored). Target under ~200 KB each; if larger, crop to the content area rather than adding a compression tool.
- **Demo data through the API**, on the e2e servers (:8011/:3011) with `e2e-*@example.com` accounts so `servers.sh cleanup` removes everything afterwards. Display names and the organization name look real ("Acme Studio", "Maya Chen", "Sam Rivera").

## Demo data (via curl against :8011, then the browser)
- Owner registers (`e2e-demo-owner@example.com`, "Maya Chen"), creates and switches to the organization "Acme Studio".
- 4–5 projects with believable names and descriptions (e.g. "Website relaunch", "Customer onboarding revamp", "Q3 pricing experiment", "Mobile app beta").
- A second user ("Sam Rivera") invited as Member and joined through the invitation link from the mail log; a pending invitation for a third address so the invitations list isn't empty.
- A custom role ("Contributor") so the roles page shows more than the defaults.
- AI: summaries generated (queue worker running) for two projects, one approved and one left as a draft to review; one writing-help suggestion shown in the edit form; a short chat (2 questions) on the main project. This also fills the AI usage page and the audit log.

## Screenshots (`docs/screenshots/`)
1. `projects.png`: projects list.
2. `project-summary.png`: project page with its approved AI summary.
3. `writing-help.png`: edit form with an "Improve with AI" suggestion ready to accept.
4. `project-chat.png`: the Ask AI chat panel with a conversation.
5. `members.png`: members and pending invitations.
6. `roles.png`: roles with permissions.
6b. `role-permissions.png`: the Edit role dialog with the permission checkboxes (added on request).
7. `audit-log.png`: audit log.
8. `ai-usage.png`: AI usage and monthly budget.
9. `mobile.png`: the project page at 390px.

## README
A new "Screenshots" section after "Features so far": a short line per image with a relative link (`docs/screenshots/...png`), grouped as Projects & AI, then Team & admin. Alt text describes what each shows.

## Backend / Frontend / Tests
No code changes, so no new tests. Out of scope.

## Notes from capturing (for retaking them)
- Creating a project already queues a summary (`ProjectCreated`); requesting one more replaces that draft. AI jobs share the organization's limit of 5 calls a minute, so run `php artisan queue:work --stop-when-empty` again after a minute if summaries stay pending.
- Invitation emails are queued: run a worker before reading the join link from `storage/logs/laravel.log`.
- `/register` allows 6 requests a minute; pause between reruns.
- The chat shot uses a 1280×1000 viewport, so a question and its whole answer fit.
- Cost of the whole session: 11 calls, $0.0016.

## Verification
- Look at each image before committing (readable, no `e2e-` emails visible where avoidable, no errors or toasts caught mid-animation, no secrets).
- `servers.sh cleanup` and `stop`, then confirm no `e2e-*` users remain in `saas_starter`.
- No `/verify` needed (docs and images only), and no reviewers (no code).

## Commits after approval
`docs: add README screenshots` (images + README), then this plan → Done.
