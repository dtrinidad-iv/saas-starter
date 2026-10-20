# 05 — Projects (first tenant-owned feature)

**Status:** Done
**Commits:** `a9e575e` (api), `881c5fc` (web)
**Commits:** —

## Goal
Members can create, browse, edit and delete projects in their current organization. This sets the pattern for every later tenant-owned feature: `BelongsToOrganization` model, policy by role, scoped route binding, server-computed permissions in the API response, cross-org tests, and a domain event.

## Permissions

| Action | Owner | Admin | Member |
|---|---|---|---|
| See projects | ✓ | ✓ | ✓ |
| Create | ✓ | ✓ | ✓ |
| Edit | ✓ | ✓ | only projects they created |
| Delete | ✓ | ✓ | – |

## Backend (`api/`)
1. **Migration** `projects`: organization_id (cascade), name, description (nullable text), created_by (users, null on delete), timestamps. Unique (organization_id, name).
2. **`Project` model** with `BelongsToOrganization`, `creator()` relation, factory.
3. **`ProjectPolicy`** implementing the table (role from `CurrentOrganization`).
4. **Actions**: `CreateProject` (sets `created_by`, dispatches `ProjectCreated`), `UpdateProject`. Delete is a single scoped `delete()`.
5. **`ProjectCreated` event** (CLAUDE.md example). No listeners yet; later features (e.g. AI summaries) hook in here.
6. **Form Requests**: name required, max 255, unique within the current organization (the org id comes from `CurrentOrganization`, never the request); description optional, max 5000.
7. **`ProjectResource`**: id, name, description, creator `{id, name}`, timestamps, and `can: {update, delete}` computed by the policy, so the UI doesn't duplicate the rules.
8. **Endpoints** (`auth:sanctum` + `organization`; `{project}` resolves through the org scope, so another org's project is a 404):

   | Endpoint | Notes |
   |---|---|
   | `GET /api/projects` | Newest first, 20 per page, optional `?search=` on name |
   | `POST /api/projects` | 201 |
   | `GET /api/projects/{project}` | |
   | `PATCH /api/projects/{project}` | |
   | `DELETE /api/projects/{project}` | 204 |

9. **Fix**: correct the comment in `OrganizationInvitationMail`. Queued jobs reload models *without* global scopes; the real catch is that further org-scoped queries inside a job need `CurrentOrganization::set()`.
10. **Tests (Pest)**: CRUD; permission matrix (incl. member editing own vs. someone else's, member can't delete their own); validation; same name allowed in another org; `organization_id` in the payload is ignored; `ProjectCreated` dispatched (`Event::fake`); search and pagination. **Cross-org**: list excludes, and show/update/delete of another org's project is 404 with the row unchanged.

## Frontend (`web/`)
11. **Types** `Project`, and a **Projects** link in the header.
12. **`/projects`**: list (name, description preview, creator, updated) with search, pagination and a **New project** modal. Reloads on organization switch.
13. **`/projects/[id]`**: details; Edit (inline form) and Delete (confirm modal) shown from `can`. Switching organization while on a project goes back to `/projects` (the project belongs to the previous org).

## Out of scope
Archiving / soft deletes, project members or per-project permissions, any listeners on `ProjectCreated`, a job helper for setting the organization in queued work (comes with the AI layer).

## Verification
- `php artisan test`, Pint, `npm run lint`, `npm run typecheck`, `npm run build`.
- Mutation check: disable the policy and confirm tests fail.
- curl run on spare ports: two orgs, CRUD per role, cross-org 404s, SSR pages render.
- Commits after approval: `feat(api): add projects`, `feat(web): add projects pages`, then this plan → Done.

## Outcome
- 129 Pest tests (28 new). Mutation checks: policy allowing everything fails 5 project tests; removing `BelongsToOrganization` from `Project` breaks the project suite (2/28 pass).
- End-to-end on spare ports: create with `can` flags, duplicate name rejected, search, another org gets 404 on read/update/delete and an empty list, SSR project page shows Edit/Delete for the owner and "Project not found" for the other org, edit, delete.
- Changed from plan: added an `inOrganization()` Pest helper for creating tenant rows; `ProjectForm` component shared by create and edit.
- Fixed: `OrganizationInvitationMail` comment (and plan 04's note): queued jobs restore models without global scopes; only further org-scoped queries need `CurrentOrganization::set()`.
- Not done: in-browser click-through (Claude in Chrome not connected).
