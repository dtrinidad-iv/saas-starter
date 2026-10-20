# SaaS Starter

A multi-organization SaaS starter: a **Laravel API** (`api/`) and a **Nuxt app** (`web/`).

Users sign up, belong to several organizations with a role (Owner, Admin, Member, or custom roles built from a list of permissions), switch between them, invite teammates by email, and manage organization-owned data, starting with Projects. Projects get AI summaries (Claude) that a teammate reviews before they count. An audit log records who did what in each organization. Each organization's data is isolated on the server: the active organization comes from the logged-in user, never from the request.

## Stack

| | |
|---|---|
| API | Laravel 13, PHP 8.4, PostgreSQL, Sanctum (Bearer tokens), Pest |
| Web | Nuxt 4, Nuxt UI 4, Pinia, TypeScript |

**Requirements:**
- **PHP 8.4+** (Pest 5 needs it), with the `pdo_pgsql` extension, and Composer.
- **Node 22.22.3+.** The version is in `web/.nvmrc`; run `nvm use` in `web/`.
- **PostgreSQL** running locally.

## Setup

### 1. Databases
Create two databases: one for the app, and one for the tests (the tests wipe theirs).
```bash
createdb -h 127.0.0.1 -U postgres saas_starter
createdb -h 127.0.0.1 -U postgres saas_starter_test
```

### 2. API
```bash
cd api
composer install
cp .env.example .env        # then set DB_* to match your Postgres
php artisan key:generate
php artisan migrate
```
Key settings in `api/.env`:

| Key | Default | What it's for |
|---|---|---|
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `saas_starter`, `postgres`, empty | The app database |
| `FRONTEND_URL` | `http://localhost:3000` | The only origin CORS allows, and the base of invitation links |
| `QUEUE_CONNECTION` | `database` | Invitation emails and AI summaries are queued |
| `MAIL_MAILER` | `log` | Locally, emails (with their invitation links) are written to `storage/logs/laravel.log` |
| `ANTHROPIC_API_KEY` | empty | Claude API key, used only by the API. Without it, summaries are marked failed |
| `AI_PROVIDER` | `anthropic` | `fake` returns canned summaries with no network calls (no key needed) |
| `AI_MODEL` | `claude-haiku-5-5` | Model for all AI features |
| `AI_RATE_LIMIT_PER_MINUTE` | `5` | AI requests per organization per minute |
| `AUDIT_RETENTION_DAYS` | `365` | Days to keep audit log entries; `0` keeps them forever |

### 3. Web
```bash
cd web
nvm use
npm ci
cp .env.example .env        # NUXT_PUBLIC_API_BASE=http://localhost:8000
```

## Run

You need three terminals:
```bash
cd api && php artisan serve          # API on http://localhost:8000
cd api && php artisan queue:work     # sends queued emails and generates AI summaries
cd web && npm run dev                # app on http://localhost:3000
```
In production also run the scheduler (`php artisan schedule:work`, or a cron entry for `schedule:run`); it prunes old audit log entries daily.
Open http://localhost:3000 and register; your first organization is created for you.

## Tests and checks
Run everything with one command, from the repo root (it finds PHP 8.4 and the right Node version for you):
```bash
.claude/skills/verify/verify.sh          # or: verify.sh api | verify.sh web
```
Or run each step yourself:
```bash
cd api && php artisan test           # Pest, against saas_starter_test
cd api && vendor/bin/pint --test app routes tests database config bootstrap/app.php bootstrap/providers.php
cd web && npm run lint && npm run typecheck && npm run build
```
Every tenant-owned feature has a test proving organization A can't read or write organization B's data.

## Features so far

| Feature | API | Web |
|---|---|---|
| Register, log in, log out, current user | `POST /api/register`, `/login`, `/logout`, `GET /api/me` | `/register`, `/login` |
| Organizations: list, create, switch | `GET/POST /api/organizations`, `POST /api/organizations/{id}/switch` | Org switcher in the header |
| Members: list, change role, remove (at least one owner always remains) | `GET/PATCH/DELETE /api/members` | `/members` |
| Roles: per-organization roles with permissions; create, edit, delete (not the built-in Owner, nor a role in use). People can only grant what they have | `GET/POST/PATCH/DELETE /api/roles`, `GET /api/permissions` | `/roles` |
| Invitations by email: invite, revoke, accept | `GET/POST/DELETE /api/invitations`, `GET/POST /api/join/{token}` | `/members`, `/join/{token}` |
| Projects: list with search and pagination (view, edit and delete from each row), create, edit, delete | `/api/projects` (REST) | `/projects`, `/projects/{id}` |
| Audit log: member, invitation, role, project and AI summary changes with who, when, IP and before/after values. Needs the "View the audit log" permission (Owner and Admin by default), which shows names and changes of everything audited (project names and changes only to those who can view projects); IP addresses are shown to owners only. Behind a load balancer, configure trusted proxies so the real client IP is recorded; filter by action, person and date; pruned after `AUDIT_RETENTION_DAYS` | `GET /api/audit-logs`, `GET /api/audit-log/actions` | `/audit-log` |
| AI project summaries: generated on create (or on request) as a draft, then approved or rejected. Rate limited per organization; every AI call is recorded in `ai_usage` with tokens and cost | `GET/POST /api/projects/{id}/summary`, `POST /api/projects/{id}/summaries/{summaryId}/approve`, `/reject` | AI summary card on `/projects/{id}` |

Organization routes act on the user's **current** organization (`users.current_organization_id`). Tenant-owned models use the `BelongsToOrganization` trait, so another organization's records return 404.

## Project layout
```
api/            Laravel API (Actions, Policies, Form Requests, Resources, Pest tests)
web/app/        Nuxt app (pages, components, Pinia stores, useApi composable)
docs/plans/     One plan per task, with status (Proposed → WIP → Done) and commit hashes
CLAUDE.md       Conventions and rules for working on this repo (also used by Claude Code)
.claude/        Claude Code skills, review agents and settings
```

## Contributing
Read [CLAUDE.md](CLAUDE.md) for conventions: thin controllers, Actions, Form Requests, organization isolation, and docs updated in the same commit as the code they describe. Then see [docs/plans/](docs/plans/README.md) for how the project was built, task by task, including known follow-ups.
