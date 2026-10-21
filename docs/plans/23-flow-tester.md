# 23 — Flow tester subagent and e2e mail helpers

**Status:** Done
**Commits:** `c9ef4b1` (claude)

## Goal
Make the plan 22 walkthrough repeatable on every plan. Plan 22 ran real flows in a browser, following links from real emails, and ended with a table of results. The helpers for that were scratch scripts, and the run filled our conversation with about 80 browser actions. This plan moves the helpers into the `e2e-smoke` skill, fixes the report format, and adds a `flow-tester` subagent. The subagent runs the scenarios with those helpers and returns only the table.

No app code changes (`api/`, `web/` untouched). Out of scope: a CI e2e suite (Playwright test files in the repo), and a web test runner.

## Decisions
- **Extend `e2e-smoke`, don't add a second skill.** It already starts the servers on :8011/:3011 and cleans up `e2e-*` accounts, and `ui-reviewer` already depends on it. Both agents use it as their shared recipe.
- **Emails come from the log, as in plan 22.** `servers.sh start --mail` runs the test API with `QUEUE_CONNECTION=sync` and `MAIL_MAILER=log`, so emails are written as they're sent, with links to :3011. This doesn't depend on the developer's `queue:work`, which may be running stale code. Env vars override `.env`, so `.env` isn't touched.
- **Only read log lines from this run.** `api/storage/logs/laravel.log` is shared with the developer's own server. `start` records the log's line count in the state dir, and the helpers read only lines after it. They also filter by recipient, which is always an `e2e-*` address (`list` shows only `e2e-*` recipients). `start --mail` leaves a marker that `mail.sh` requires, so a run without `--mail` fails with that reason instead of "no email". If the log got shorter during a run, both read all of it and warn.
- **`flow-tester` is a subagent, on Sonnet.** It follows steps and doesn't design anything, so the cheaper model fits, as it does for `ui-reviewer`. Its browser actions stay out of the main conversation, and it can run in the background while I keep working.
- **No edits.** Tools: `Read, Grep, Glob, Bash, mcp__playwright__*`, with no Edit or Write. It may create and delete `e2e-*` data through the app and curl, but never changes code. It reports failures, and I fix them.
- **The caller owns the servers.** I start them with `--mail` and stop them afterwards, the same contract `ui-reviewer` has. That way both agents can share one build in parallel.
- **Each agent cleans up only its own data.** `servers.sh cleanup [prefix]` takes an email prefix (default `e2e-`). `flow-tester` uses `e2e-ft-*` accounts and runs `cleanup e2e-ft-`, so it doesn't delete accounts `ui-reviewer` is still using.
- **Scenarios come from the plan.** The plan template's Verification section gets a "Flows" list: numbered, user-level steps with the expected result. The caller passes that list, or the plan number and the agent reads it.
- **No new dependency.**

## Tooling (`.claude/`)
- `skills/e2e-smoke/servers.sh`
  - `start [--mail]`: `--mail` adds `QUEUE_CONNECTION=sync MAIL_MAILER=log` to the API's environment. `start` always records the `laravel.log` line count in `$state/log.start`.
  - `cleanup [prefix]`: the prefix must start with `e2e-` (anything else is refused) and defaults to `e2e-`. Organizations shared with other users are kept, as today.
  - `errors`: prints `ERROR`-or-worse entries in `laravel.log` since `start`, leaving out Pest runs (`testing.*`). It prints "none" when there are none. (`api.log` from PHP's built-in server has no status codes; unhandled 500s are logged in `laravel.log` anyway.)
- `skills/e2e-smoke/mail.sh` (new; reads only the lines since `log.start`)
  - `list`: one line per email: recipient and subject.
  - `link <path> [recipient]`: the newest link to `http://localhost:3011/<path>` (`verify-email`, `reset-password`, `join`, …), optionally only from emails to that recipient. It decodes `&amp;` and strips the emails' CRLF line endings (the log mailer writes bodies already decoded, so quoted-printable needs no handling). It exits 1 with a message when there's no match.
  - `count <recipient>`: how many emails went to that address.
- `skills/e2e-smoke/SKILL.md`
  - Document `--mail`, `mail.sh`, `errors` and the cleanup prefix, and add `mail.sh` to `allowed-tools`.
  - Add a step "Emails: follow links from the email, not by building URLs".
  - Fix the report format:
    - a table `| # | What I did | Result |` with ✅/❌ and one line of evidence (status code, message shown, link followed);
    - then **Emails** (`mail.sh list`), **Errors in the log** (`servers.sh errors`), **Data created and cleaned up**;
    - then **Noticed** (things outside the scenarios, one line each).
- `agents/flow-tester.md` (new, `model: sonnet`)
  - **Input:** numbered scenarios, or a plan number to read Verification → Flows from. Test accounts are `e2e-ft-<name>@example.com`.
  - **Setup:** check `servers.sh status`. If the servers aren't running, or weren't started with `--mail` and the flows need email, stop and ask the caller. Never use :3000/:8000.
  - **How:** set up preconditions (register, invite, verify) with curl. Do the steps a user would take in the browser. Follow emailed links with `mail.sh link`. Use `browser_snapshot` to read pages, and take a screenshot only for a failure, saved under `.playwright-mcp/`.
  - **Budget:** about 100 browser actions. If it runs out, report what's done and what's left.
  - **Rules:**
    - Don't edit files. Bash is for curl to :8011/:3011, the e2e-smoke scripts, and read-only git/grep.
    - Never claim a check that didn't run. If the Playwright tools are missing, say so and do the API checks only.
    - Always run `servers.sh cleanup e2e-ft-`, even after a failure.
  - **Report:** the e2e-smoke format. For a failure, give the expected result, the actual result, and the likely file.
- `hooks/flow-tester-servers.sh` (new, PreToolUse on Bash in the agent's frontmatter, like `only-api-tests.sh`): allows `servers.sh` only as `status`, `errors` or `cleanup e2e-ft-`, so the e2e-ft- limit doesn't rest on the agent's instructions alone (from the security review).
- `skills/plan-task/SKILL.md`: the template's Verification section gets a "Flows" list for plans with a user-facing flow, or "Flows: none" with why.
- `settings.json`: allow `Bash(.claude/skills/e2e-smoke/mail.sh *)`.

## Docs
- `CLAUDE.md`, "Claude Code tooling":
  - add `flow-tester` to the subagents (on Sonnet, no edits, uses `e2e-*` data);
  - mention `--mail` / `mail.sh` under `/e2e-smoke`;
  - add to "Before reporting a change as done": "after tests pass, run `flow-tester` on the plan's Flows when there's a user-facing flow (start the servers with `--mail`)".
- Root `README.md`: no change. The `.claude/` line in the project layout already covers it, and setup and features don't change.

## Tests
No app code, so no Pest changes. The scripts are checked by running them (see Verification). `verify` still runs to confirm nothing else moved.

## README screenshots
None. Nothing in the app changes.

## Verification
- `bash -n` on both scripts. `servers.sh cleanup foo-` is refused, and `servers.sh errors` / `mail.sh list` print "none" on a fresh start.
- Dry run: start with `--mail`, then run `flow-tester` in the background on three plan 22 flows:
  1. Sign up, then open the verification link from the email while logged out: you're sent to log in, then back, and verified.
  2. Forgot password → open the reset link from the email → set a password: you're logged in.
  3. A verified user invites an unverified one, who tries to accept: refused with the verify message. They resend from the banner, follow the new link, and accept: they've joined.
- Expect: the report table in the agent's result, no browser output in this conversation, and no `e2e-ft-*` users left (checked with tinker).
- `verify` skill. Reviewers: `code-reviewer` and `security-auditor` on the scripts (the cleanup prefix guard, log reading). Tenant isolation, database and UI reviews don't apply, since there's no app code or pages.

Flows: none (tooling only; the dry run above is the flow check).

## Follow-ups
From the reviews, not done here:
- `servers.sh cleanup` deletes organizations with a mass `delete()`, which skips model events and audit entries. This predates this plan and only touches `e2e-*` data.
- With `--mail`, emailed links and their tokens stay in `laravel.log` after the run. They belong to deleted test accounts, so they should no longer work. A separate log file for the :8011 API would fix this, and would also stop the developer's own server from adding entries to the run's window.

## Commits after approval
`chore(claude): add flow-tester agent and e2e mail helpers` (`.claude/`, `CLAUDE.md`), then `docs: mark flow tester plan done`.
