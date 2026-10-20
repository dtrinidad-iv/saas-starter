# 03 — Organizations

**Status:** Done
**Commits:** `dafbcdc` (api), `3415386` (web)

## Goal
Users belong to many organizations with a role and have one server-side current organization. Tenant-owned models get `BelongsToOrganization`.

## Scope
- Tables: `organizations`, `organization_user` (role, unique per pair), `users.current_organization_id`.
- `OrganizationRole` enum, `Membership` pivot, `CurrentOrganization` (scoped; re-checks membership every request; `set()` for jobs).
- `BelongsToOrganization`: org-scoped reads (fail closed), `organization_id` forced on create, no moving rows.
- `GET/POST /api/organizations`, `POST /api/organizations/{id}/switch` (404 for non-members); `/api/me` includes `current_organization`.
- Registration creates a personal organization.
- Web: `organization` store, `OrgSwitcher` in the header (switch + create modal), dashboard shows org and role.

## Outcome
- 45 Pest tests, incl. trait isolation; mutation check: disabling the scope fails 8/10 isolation tests.
- Changed from plan: list returns `meta.current_organization_id` instead of per-item `is_current`; switcher refetches on open.
- Fixed: cross-store lookup during SSR now goes through the request's own Pinia instance.
- Out of scope then: member management → plan 04.
