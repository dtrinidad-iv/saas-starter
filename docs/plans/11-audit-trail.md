# 11 — Audit trail

**Status:** Done
**Commits:** `673ee08` (api), `a763a32` (web), `fa6f925` (claude)

## Context
Organizations need to see who did what and when: membership and permission changes (security-relevant) and changes to their data. Choices made: record **both** curated events and automatic model changes, gate viewing behind a **new permission**, **prune** after a configurable retention, and ship a **page with filters**.


## Design

### Storage — `audit_logs` (append-only, tenant-owned)
New migration: `id`, `organization_id` (FK, cascadeOnDelete), `actor_id` (nullable FK users, nullOnDelete), `action` (string 64, e.g. `project.updated`), `subject_type` (string 64, nullable), `subject_id` (nullable), `subject_label` (string, nullable — name snapshot so deleted subjects still read well), `changes` (jsonb nullable, `{field: {old, new}}`), `metadata` (jsonb nullable), `ip_address` (string 45, nullable), `created_at` only.
Indexes: `(organization_id, created_at)`, `(organization_id, action)`, `(organization_id, actor_id)`.

`App\Models\AuditLog`: `BelongsToOrganization`, `UPDATED_AT = null`, written with `forceFill` (like `AiUsage`), `updating` throws `LogicException`. `MassPrunable`; `prunable()` must use `withoutGlobalScope(OrganizationScope::class)` (the scheduler has no current org, so the scope would match nothing) and returns no rows when retention is 0.

### Action catalogue — `App\Enums\AuditAction`
String enum with `label()` and `group()` (mirrors `Permission`). Cases:
- Automatic: `project.created|updated|deleted`, `role.created|updated|deleted`
- Curated: `organization.created`, `member.invited`, `invitation.revoked`, `member.joined`, `member.role_changed`, `member.removed`, `summary.requested`, `summary.approved`, `summary.rejected`

Not logged: org switching and login (per-user, not org admin events).

### Writing
- `App\Actions\Audit\RecordAudit::handle(AuditAction, ?Model $subject = null, array $changes = [], array $metadata = [], ?Organization $organization = null)`. Actor = `Auth::user()` (null in jobs/console), IP from the request when in HTTP. When `$organization` is passed it writes inside `CurrentOrganization::runAs()` — needed for `AcceptInvitation`/`CreateOrganization`, which run outside the `organization` middleware.
- `App\Models\Concerns\Auditable` trait: on `created/updated/deleted` calls `RecordAudit` with action `"{auditName()}.{event}"` and a diff of changed attributes. Excludes `id`, `organization_id`, timestamps, `$hidden` and a per-model `$auditExclude`; truncates long strings (500 chars). Skips `updated` when nothing relevant changed. `Auditable::withoutAuditing(Closure)` to suppress.
- Apply `Auditable` to `Project` and `Role` (role permissions are a jsonb column, so permission edits show up in the diff). Not on `ProjectSummary` (job status churn) or `AiUsage`.
- Curated calls added to: `CreateOrganization` (wrap `CreateDefaultRoles` in `withoutAuditing`, log one `organization.created`), `InviteMember` (email + role name; never the token), `AcceptInvitation` (`member.joined`, in the invitation's org), `ChangeMemberRole` (old/new role name), `RemoveMember`, `RequestProjectSummary`, `ReviewProjectSummary`.
- Thin-controller cleanup so nothing bypasses logging: new `App\Actions\Organizations\RevokeInvitation` (from `InvitationController@destroy`) and `App\Actions\Projects\DeleteProject` (from `ProjectController@destroy`).
- Writes happen inside the existing `DB::transaction`s, so a rolled-back change leaves no audit row.

### Permission
- `Permission::AuditLogView = 'audit_log.view'`, group `Organization`, label "View the audit log". Admin gets it automatically (all but `roles.manage`); Member doesn't.
- New migration adding `audit_log.view` to existing roles named `Admin` that are not owner (jsonb append, idempotent).

### Reading
- `GET /api/audit-logs` (in the `organization` group): `AuditLogPolicy::viewAny` → `CurrentOrganization::can(AuditLogView)`. `IndexAuditLogRequest` validates `action` (enum), `actor_id` (int), `from`/`to` (dates, `to >= from`). `latest('id')->with('actor:id,name')->paginate(25)->withQueryString()`.
- `GET /api/audit-log/actions`: action catalogue (value, label, group) for the filter, like `/api/permissions`.
- `AuditLogResource`: id, action, action_label, actor `{id, name}|null`, subject `{type, id, label}`, changes, metadata, ip_address, created_at.

### Retention
`config/audit.php` → `retention_days` from `AUDIT_RETENTION_DAYS` (default 365, `0` = keep forever; documented in `.env.example`). `routes/console.php`: `Schedule::command('model:prune', ['--model' => [AuditLog::class]])->daily()`.

### Frontend
- `web/app/types/api.ts`: `'audit_log.view'` in `Permission`, `AuditLog`, `AuditActionInfo`.
- `web/app/pages/audit-log.vue`, built on the `projects/index.vue` pattern (URL query state, `useAsyncData('audit-log', …)`, past-the-end page fix, reset on org switch). Fetch is gated on `can('audit_log.view')` with `watch` (like `members.vue` invitations); no permission → "You don't have access" `UAlert`.
- Filters: action `USelect` (from `/api/audit-log/actions`), actor `USelect` (from `/api/members`), from/to date `UInput type="date"` (no new dependency). Table: time, actor, action label, subject label; expandable row lists the `changes` as old → new and metadata. All values rendered as text (user content is untrusted).
- `web/app/layouts/default.vue`: "Audit log" nav item (`i-lucide-scroll-text`) only when `can('audit_log.view')` — the first permission-gated nav link.

### Docs
- `CLAUDE.md`: audit convention — tenant models users edit use `Auditable`; non-CRUD actions call `RecordAudit`; never log secrets/tokens; add new `AuditAction` cases for new events.
- `.claude/skills/tenant-feature`: add the `Auditable` step.
- `README.md`: audit log feature + retention key. `.env.example`: `AUDIT_RETENTION_DAYS`.

## Tests (Pest, `api/tests/Feature/AuditLog/`)
- **Isolation**: org B never sees org A rows; `actor_id` of an org A user from org B returns nothing; writes from `AcceptInvitation` land in the invitation's org, not the user's previous current org.
- **Permissions**: Owner/Admin 200, Member 403, custom role with/without `audit_log.view`; migration grants Admin in existing orgs.
- **Auditable**: project create/update/delete rows with diffs; excluded fields absent; no-op update logs nothing; org creation logs one `organization.created` and no `role.created`.
- **Curated**: each listed action writes the expected action, actor, subject and metadata; invitation token never stored; failed (rolled-back) action leaves no row.
- **Filters & validation**: action/actor/date filters, invalid enum → 422, pagination.
- **Retention**: `model:prune` deletes old rows across all orgs; `0` keeps everything; `update` on a row throws.

## Steps / commits
1. `feat(api): add audit log` — migrations, enum, model, `RecordAudit`, `Auditable`, new Actions, endpoints, permission, scheduler, tests, `.env.example`, `CLAUDE.md`, tenant-feature skill.
2. `feat(web): add audit log page` — types, page, nav, README.
3. `docs: mark audit trail plan done`.

## Verification
- `/verify` (Pint, `php artisan test`, lint, typecheck, `npm run build`).
- Reviewers in parallel: code, security, tenant isolation, database; ui-reviewer on `/audit-log` (390px, filters, empty state, Member sees no link/no access).
- `/e2e-smoke` on :8011/:3011: edit a project and change a member's role in org A → entries appear with diffs; org B shows none; a Member gets 403 from `/api/audit-logs`.

## Changes from review
- Project names and changes are hidden from viewers without `projects.view`.
- IP addresses are returned to owners only (personal data; `audit_log.view` alone doesn't show them).
- Indexes end in `id` to match `ORDER BY id DESC`: `(organization_id, id)`, `(organization_id, action, id)`, `(organization_id, actor_id, id)`, plus `(organization_id, created_at)` and `created_at` for pruning. `created_at` is not null.
- Project and Role create/update/delete Actions run in a transaction so the Auditable entry commits with the change; `ProjectCreated` is dispatched after commit.
- `ChangeMemberRole` / `RemoveMember` re-read the member's role under a row lock, so the recorded old role is correct under concurrent changes.
- `Auditable` compares full values and only shortens what it stores, and throws if a model is changed while acting in another organization.
- Automatic summary requests are recorded too (the listener passes the project creator as requester).
- Filters use `router.replace` like the projects page, so Back leaves the page instead of stepping through filter changes.

## Follow-ups
- Switch the list to cursor pagination if organizations' logs grow large (numbered pages run a count per request).
- Configure trusted proxies when deploying behind a load balancer, so the real client IP is recorded.
- `npm audit`: 12 existing findings (node-forge, braces, esbuild) in dev/build tooling; needs a Nuxt upgrade.
