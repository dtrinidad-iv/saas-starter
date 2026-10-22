# 28 — CI on GitHub Actions

**Status:** Done
**Commits:** `10dc905` (ci, squash of PR #1)

## Goal
Run the same checks as `/verify` (Pint, Pest, ESLint, nuxt typecheck, build) on GitHub for every push to `main` and every pull request, so a broken commit shows up even when nobody ran `verify.sh` locally. Right now those checks only run on a developer's machine.

Out of scope:
- e2e or browser tests in CI (`e2e-smoke` and `flow-tester` stay local);
- deployment;
- `composer audit` / `npm audit` (they fail on advisories in packages we don't control, which would make CI red without a code change; `security-auditor` already runs them);
- branch protection rules. You switch these on in the GitHub settings if you want a red check to block merging. The README says how.

## Decisions
- **One workflow, two parallel jobs: `api` and `web`.** Neither depends on the other, so a web lint error doesn't hide a Pest failure, and each job's log is short. The steps are the same commands `verify.sh` runs. CI doesn't call `verify.sh` itself, because that script hunts for Homebrew PHP and nvm, which CI doesn't need.
- **Triggers:** `push` to `main`, `pull_request`, and `workflow_dispatch` (re-run by hand). `concurrency` cancels an older pull request run when a new push arrives; runs on `main` are never cancelled, so every commit there keeps its result.
- **Least privilege:** `permissions: contents: read`, so no secrets and no write token. The workflow needs no repository secrets: no Claude or Stripe keys.
- **Postgres as a service container** (`postgres:17`, trust auth, database `saas_starter_test`). The name ends in `_test`, so Pest's rebuild passes the destructive-command guard (`AppServiceProvider::guardDestructiveDatabaseCommands`) the same way it does locally. `phpunit.xml` already sets `DB_DATABASE=saas_starter_test`, `AI_PROVIDER=fake`, `QUEUE_CONNECTION=sync` and `MAIL_MAILER=array`.
- **Environment:** `cp .env.example .env` then `php artisan key:generate`. Job-level env sets `DB_HOST=127.0.0.1`, `DB_USERNAME=postgres`, `AI_PROVIDER=fake` and `BILLING_PROVIDER=fake`, so nothing can reach Claude or Stripe even outside `phpunit.xml`. `TestCase` already blocks stray HTTP. The `.env` file exists only on the throwaway runner; the repo's `.env` is never touched.
- **Versions come from the repo, not duplicated:**
  - PHP 8.4 (Pest 5 needs it; matches `verify.sh`), with the `pdo_pgsql` extension;
  - Node from `web/.nvmrc` (`node-version-file`).
- **Caching:** Composer's cache keyed on `api/composer.lock`, and npm's built-in cache in `setup-node` keyed on `web/package-lock.json`. Installs use `composer install --no-interaction --prefer-dist` and `npm ci`. `npm ci` runs `postinstall` (`nuxt prepare`), which typecheck needs.
- **Actions:**
  - `actions/checkout`, `actions/setup-node` and `actions/cache`, from GitHub;
  - `shivammathur/setup-php`, the standard way to get a given PHP version and extensions on GitHub's runners.

  These are CI tools, not app dependencies, but they're new outside code, so I'm noting them for your OK. GitHub's own actions are pinned to major tags (`@v5`, `@v4`). From the security review, the third-party `setup-php` is pinned to a commit SHA so a moved tag can't change what runs, and checkout uses `persist-credentials: false`. A Dependabot config for `github-actions` (7-day cooldown) keeps tags and the SHA current. It opens PRs only for the workflow file; Composer and npm stay manual, as now.
- **Timeouts:** 15 minutes per job, so a hung test can't burn runner minutes.

## Files
- `.github/workflows/ci.yml` (new)
  - `api` job: checkout → setup-php 8.4 (`pdo_pgsql`, coverage none) → Composer cache → `composer install` → `.env` + `key:generate` → `vendor/bin/pint --test app routes tests database config bootstrap/app.php bootstrap/providers.php` → `php artisan test`.
  - `web` job: checkout → setup-node (from `.nvmrc`, npm cache) → `npm ci` → `npm run lint` → `npm run typecheck` → `npm run build`.
  - Both use `working-directory` defaults (`api` / `web`).
- `.github/dependabot.yml` (new): `github-actions` ecosystem, weekly.

No `api/` or `web/` code changes.

## Docs
- Root `README.md`:
  - a CI badge under the title (`actions/workflows/ci.yml/badge.svg`);
  - in "Tests and checks", one line: GitHub Actions runs the same checks on every push to `main` and every PR (`.github/workflows/ci.yml`), plus a note that branch protection can require them;
  - `.github/` in "Project layout".
- `CLAUDE.md`, "Commands": "CI (`.github/workflows/ci.yml`) runs the `/verify` steps on push and PR; keep the two in step when a check changes." `verify/SKILL.md` gets the same one-line reminder.
- `.env.example`: no new keys.

## Tests
No app code, so no new Pest tests. The proof is the workflow itself:
- a green run on a branch pushed for this plan;
- one deliberate red run, on a throwaway commit on that branch with a Pint violation, that shows the `api` job fails and the `web` job still finishes. That commit is reverted before merging, so it never reaches `main`.

## README screenshots
None. There's no new page; the badge is the only visible change.

## Verification
- `verify` locally (nothing should move);
- push a branch `ci/github-actions`, then watch with `gh run watch`:
  - both jobs green;
  - then the deliberate Pint failure: `api` red, `web` green;
  - then the revert: green again.
- Reviewers: `code-reviewer` and `security-auditor` on the workflow (permissions, no secrets, pinned actions, triggers). `tenant-isolation-reviewer` doesn't apply: no models, queries or routes change. I'll note in the summary that it was skipped and why. No database or UI reviewers.

Flows: none. There's no user-facing flow in the app.

## Commits after approval
1. `ci: run Pint, Pest, lint, typecheck and build on GitHub Actions` (workflow, Dependabot, README, CLAUDE.md, verify skill)
2. `docs: mark CI plan done` (this plan → Done with the hash, and the index)

Pushed as the branch `ci/github-actions` first, so CI runs before anything reaches `main`. After a green run, merged into `main` with your OK.
