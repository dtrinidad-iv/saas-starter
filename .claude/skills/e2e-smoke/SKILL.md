---
name: e2e-smoke
description: Check a flow end to end against real running servers - API on :8011 and the built web app on :3011 (never :3000/:8000) - with curl or the Playwright browser, then remove the test data. Use after a feature passes its tests, to confirm it works in the actual app, including cross-organization checks.
argument-hint: "[flow to check, e.g. projects]"
allowed-tools: Bash(.claude/skills/e2e-smoke/servers.sh *)
---

# End-to-end smoke check

Flow to check: $ARGUMENTS

## 1. Start the servers
```bash
.claude/skills/e2e-smoke/servers.sh start      # builds web first; SKIP_BUILD=1 to reuse .output
```
- Port :3000 belongs to the developer's other projects, and :8000 is their own `artisan serve`. Never stop or reuse those ports.
- The servers use the dev database (`saas_starter`), so every account you create must be `e2e-<something>@example.com`. Cleanup only deletes those.

## 2. Set up two organizations
Register two users through the API, so each gets their own organization, and keep their tokens:
```bash
curl -s -X POST localhost:8011/api/register -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"E2E A","email":"e2e-a@example.com","password":"password123","password_confirmation":"password123"}'
```
- In zsh, build JSON with `printf '%s'`, not `echo`, which mangles `\n`.
- Variables aren't word-split in zsh, so put each header in its own `-H` argument.

## 3. Check the flow
- **API (curl):** the happy path, the permission cases (`can` flags, 403 for the wrong role), and that user A gets **404** for B's records on read, update and delete and never sees them in lists or search.
- **Browser (Playwright MCP, `mcp__playwright__*`):**
  - Store a token in the cookie by logging in through the `/login` form at `http://localhost:3011`.
  - Click through the flow and take screenshots of the key states: list, empty, form errors, detail, not found.
  - Read the console messages and report any errors.
  - Also check at 390px width.
- If the Playwright tools aren't available, say so and do the API checks only. Never claim a browser check you didn't run.

## 4. Clean up, always
Run this even if a check failed:
```bash
.claude/skills/e2e-smoke/servers.sh cleanup    # e2e-*@example.com users, their tokens, organizations only they belong to
.claude/skills/e2e-smoke/servers.sh stop
```

## Report
A short table of each check with pass or fail, what failed and why (with the response or screenshot), and confirmation that the test data was removed.
