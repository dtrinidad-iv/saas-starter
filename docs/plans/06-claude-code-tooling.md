# 06 — Claude Code tooling (skills, subagents, MCP)

**Status:** Done
**Commits:** `a9770dc` (claude)

## Goal
Write the project's repeated workflows down as Claude Code config in the repo (`.claude/`, `.mcp.json`), so any session follows them without re-explaining. They're also a starting point for anyone who clones the starter. No app code changes.

## Skills (`.claude/skills/<name>/SKILL.md`)
Each skill is a short checklist that points to real files and doesn't copy code.

| Skill | When it's used | What it does |
|---|---|---|
| `plan-task` | "plan X", before any change touching more than 3 files | Picks the next `NN`, writes `docs/plans/NN-task-name.md` (Context, Steps, Tests, Verification) with Status Proposed, updates the README index, then stops for approval |
| `ship-plan` | after approval ("all good, commit and push") | Runs `verify`, makes separate api, web and docs commits, records the hashes and marks the plan Done, then pushes |
| `verify` | before saying any task is done | Runs Pint, `php artisan test`, `npm run lint`, typecheck and `npm run build`, using the right PHP 8.4 and Node 22 paths, and reports the results |
| `tenant-feature` | "add <thing> to organizations" | A step-by-step guide that uses Projects as the reference: migration → model with `BelongsToOrganization` → policy → actions → form requests → resource with `can` → controller and route in the `organization` group → isolation, permission and CRUD tests → web types and pages |
| `e2e-smoke` | to check a flow against real servers | Starts the API on :8011 and the web build on :3011 (never :3000), runs a scripted check with curl (or the browser, once the MCP below is set up), then cleans up `e2e-*` users |

## Subagents (`.claude/agents/<name>.md`)
Reviewers only read code. They can't edit, and their Bash use is limited to read-only commands (git diff, log and show, plus audits). Each one has a narrow focus so their reports don't repeat each other. Every finding includes file and line, severity (high, medium or low), what goes wrong and a suggested fix, and each agent stays inside its own lane. The final report is what you see; I relay it and you decide what gets fixed.

**Reviewers (read-only)**

| Agent | Focus | Doesn't cover |
|---|---|---|
| `code-reviewer` | Correctness and our conventions across api and web: thin controllers, logic in Actions, Form Requests, Resources, `useApi` only, `<script setup lang="ts">`, Pinia for user and organization, Nuxt UI before custom components, SSR safety, error, empty and loading states, naming and dead code | Security, tenancy, database performance |
| `security-auditor` | General app security: authorization on every route and policy, mass assignment, SQL and `whereRaw` injection, XSS (`v-html`), open redirects, token handling, CORS, rate limits, secrets in code or Nuxt runtime config, invitation token handling, and the AI rules in CLAUDE.md (keys stay server-side, prompt injection). It runs `composer audit` and `npm audit` and reports the results | Cross-organization access (handled by the next agent) |
| `tenant-isolation-reviewer` | Deep checks for this project's multi-tenancy: models missing `BelongsToOrganization`, `withoutGlobalScopes` without a reason, an organization id from the request, validation rules not scoped to the organization, queued jobs that don't set `CurrentOrganization`, missing org A/org B tests | Everything else |
| `database-reviewer` | Migrations and queries: never editing a committed migration, foreign keys and `onDelete`, indexes (`organization_id` first), unique constraints per organization, N+1 queries (missing `with`/`load`), unbounded queries without pagination, transactions and `lockForUpdate` where races matter | Application logic |
| `ui-reviewer` | The running web app (needs Playwright MCP): clicks through the changed pages on :3011, checks loading, empty and error states, phone width, keyboard focus and labels, and errors in the browser console. Its report includes screenshots | Code style |

**Builders (may edit, but only in their own area)**

| Agent | Area | Purpose |
|---|---|---|
| `laravel-test-writer` | `api/tests/**` only | Writes missing Pest tests with our helpers (`userInOrganization`, `addMember`, `inOrganization`), runs them, and checks that each new test fails when the code it guards is broken |

**How they're used**
- I run the reviewers in parallel on the diff before I report a plan as finished: `code-reviewer`, `security-auditor` and `tenant-isolation-reviewer` every time, `database-reviewer` when there are migrations or queries, and `ui-reviewer` when pages change.
- You can also call one directly, e.g. "use security-auditor on the whole api".
- Built-in `/code-review` and `/security-review` already exist but are generic. These agents know this project's rules from CLAUDE.md.

**Skipped for now:** a web unit-test writer, since `web/` has no test runner yet and adding Vitest is a separate dependency decision.

## MCP servers (`.mcp.json`, project scope)
Each one needs your OK. My recommendation is below.

| Server | Why | Cost | Recommend |
|---|---|---|---|
| **Playwright** (`npx @playwright/mcp`, pinned to 0.0.83) | Fills our one testing gap: no plan so far was clicked through in a real browser. With it I can drive the :3011 build headlessly and take screenshots | Downloads Chromium (~150 MB) on first use. No change to `package.json` | Yes |
| **Nuxt UI** (`https://ui.nuxt.com/mcp`, remote HTTP) | Current Nuxt UI v4 component docs and props, so I don't guess at APIs | Nothing to install | Yes |
| **Nuxt** (`https://nuxt.com/mcp`, remote HTTP) | Nuxt 4 docs | Nothing to install | Yes |
| **Laravel Boost** (`composer require laravel/boost --dev`) | Database schema, read-only queries, `laravel.log` and Laravel 13 docs search through MCP | A new composer dev dependency. Its installer also writes the `CLAUDE.md`/`AGENTS.md` files you deleted in plan 01; I'd set up only its MCP server and skip the guideline files | Optional |

## Also
- `.claude/settings.json`: allow the safe commands these workflows run (pest, pint, `npm run lint` and `npm run build`, git status, diff and log) so they prompt less. It won't allow push, `.env` edits or migrations.
- CLAUDE.md: a short "Claude Code tooling" section listing the skills and agents.
- Personal settings stay in `.claude/settings.local.json`, which is gitignored.

## Verification
- `claude mcp list` shows the servers you chose as connected.
- Each skill appears in `/skills` and runs once:
  - `verify` on the current code.
  - `plan-task` with a dry run. I'll check its output and then delete the draft.
- Each reviewer gets a deliberately broken change and must flag it; then I revert it:
  - `tenant-isolation-reviewer`: the trait removed from `Project`.
  - `security-auditor`: a `v-html` on the project description, plus `whereRaw` with the search string.
  - `code-reviewer`: business logic moved into the controller.
  - `database-reviewer`: an N+1 query on `creator`.
  - `ui-reviewer`: the empty state removed.
- If Playwright is approved: a browser click-through of Projects (log in → create → edit → delete → another organization sees "not found"), with screenshots.

## Commits after approval
- `chore(claude): add skills, subagents and MCP config`
- Then this plan moves to Done.

## Outcome
- **Built:**
  - 5 skills, 6 subagents, `.mcp.json` (playwright 0.0.83 headless and isolated, nuxt-ui, nuxt), `.claude/settings.json`;
  - a hook that limits `laravel-test-writer` to editing `api/tests/` (`.claude/hooks/only-api-tests.sh`);
  - a root `.gitignore` for `settings.local.json` and `.playwright-mcp/`;
  - a CLAUDE.md section.
- **Changed from the plan:**
  - `verify` and `e2e-smoke` got scripts (`verify.sh`, `servers.sh`) instead of written-out commands. They find PHP 8.4 and the `.nvmrc` Node themselves, so they work regardless of the shell's PATH.
  - `servers.sh stop` stops servers by port (php or node only), because `$!` pointed at wrapper processes.
  - `laravel-test-writer` doesn't break app code to test its tests. It proposes the code changes for the caller to run, and never uses stash or checkout on the working tree.
  - Seeded bugs for the reviewer test go in a patch file in the scratchpad. Adding vulnerabilities to real source files was (rightly) blocked.
- **Settings:**
  - Pre-approved: verify, the e2e servers, read-only git, tests, lint and build, the docs MCP servers, and normal Playwright browsing.
  - Still prompting: Playwright `evaluate`, `run_code_unsafe`, file upload and drop.
  - Denied: editing or writing `.env`, and force-push.
- **Verified:**
  - `verify.sh` passes (129 tests, lint, typecheck, build).
  - `servers.sh` start, status, cleanup and stop work. Cleanup deleted only the `e2e-script@example.com` user and its organization.
  - The hook allows `api/tests/**` and blocks app paths, `../` escapes and `api/testsX`.
  - The frontmatter of every agent and skill parses as YAML.
  - The skills appear in the session.
- **After the restart:**
  - `claude mcp list` shows playwright, nuxt-ui and nuxt all connected.
  - **Reviewers on the seeded-bug patch.** Every seeded bug was caught by the reviewer that owns it, with a correct fix:
    - `code-reviewer`: logic in the controller, `creator` missing from list responses, dead code.
    - `security-auditor`: SQL injection, stored XSS.
    - `tenant-isolation-reviewer`: the trait removed, `organization_id` taken from the request.
    - `database-reviewer`: dropped eager loading.

    Two problems:
    - **Lanes.** Each reviewer also fully analyzed the other reviewers' bugs, so the four reports mostly repeated each other. I added a "Stay in your lane" section and an "Outside my lane" list to the four reviewers. Running two of them again in the same session made little difference, because agent definitions appear to load only at startup. The rule takes effect after the next restart.
    - **Accuracy.** Two reviewers said a test for "`organization_id` in the request is ignored" was missing; it already exists in `ProjectCrudTest`. The lane section now says to grep `api/tests` before claiming a test is missing.
  - **`ui-reviewer` on the committed Projects pages (real browser).**
    - The full flow works with no console or hydration errors, including another organization's project showing "Project not found".
    - It found real issues in the current code (not seeded); they're listed for a follow-up plan below.
    - Its 27 screenshots are in `.playwright-mcp/` (gitignored).
  - Test data cleanup removed 2 e2e users, their tokens and their organizations.
- **Not done:** a dry run of `plan-task`. It's documentation only and will run for real on the next plan.

## Follow-up found by ui-reviewer (not part of this plan)
- **Medium:**
  - The header overflows at 390px: the page is 601px wide. Confirmed from a screenshot.
  - After a duplicate-name error, a quick click on Save is lost: clearing the error moves the button under the cursor.
  - On the detail page, focus isn't moved into the inline edit form or back to the Edit button afterwards.
- **Low:**
  - The focus ring is faint.
  - The search field has no label.
  - Each row's link name includes the description.
  - Table columns are hidden on mobile with no hint.
  - The not-found page returns 200 and has a generic title; `/projects/abc` requests `NaN`.
  - The current-page link has no `aria-current`.
  - Cancel stays enabled while a delete is in progress.
  - The search term isn't kept in the URL.
