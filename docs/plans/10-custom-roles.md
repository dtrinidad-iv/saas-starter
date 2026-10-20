# 10 — Custom roles and permissions

**Status:** Done
**Commits:** `bdd3d3f` (api), `10efdee` (web)

## Context
Roles are currently a fixed enum (`App\Enums\OrganizationRole`: owner/admin/member), and every policy hard-codes what each one may do (`ProjectPolicy`, `MembershipPolicy`, `OrganizationInvitationPolicy`). Assigning those three roles already works on `/members` (plan 04). The new goal: each organization defines its own roles (e.g. "Editor", "Viewer") from a fixed catalogue of permissions, and policies check **permissions** instead of role names. Owner stays built in.

## Permission catalogue (in code, `App\Enums\Permission`)
| Group | Permission | Replaces today's rule |
|---|---|---|
| Projects | `projects.view` | any member |
| | `projects.create` | any member |
| | `projects.update_own` | member editing a project they created |
| | `projects.update_any` | owner/admin |
| | `projects.delete` | owner/admin |
| Members | `members.invite` (also see and revoke invitations) | owner/admin |
| | `members.change_role` | owner/admin |
| | `members.remove` | owner/admin |
| Roles | `roles.manage` | new |

AI summaries keep following project edit rights (`ProjectSummaryPolicy` already delegates to `ProjectPolicy::update`). Seeing the member list and leaving stay open to everyone in the org.

## Rules (no privilege escalation)
- **Owner** is a built-in role per org (`is_owner = true`). It has every permission, can't be renamed, edited or deleted. Only owners can give or take away the owner role, invite as owner, or remove an owner. The last-owner rule (`EnsuresAnOwnerRemains`) stays.
- A non-owner with `roles.manage` can only grant permissions they have, and only edit or delete roles that can do **strictly less** than theirs (not their own, not a peer's with the same permissions: emptying it would be a removal they can't do directly).
- Assigning a role (`members.change_role`, invitations with `members.invite`): the role being given **and** the member's current role must be subsets of the actor's permissions. That keeps today's behavior (admin ↔ member, never touching owners). Revoking or replacing (re-inviting) a pending invitation also needs its role to be covered.
- Removing someone (`members.remove`): the target's role must be a **strict** subset of the actor's permissions. That keeps today's behavior (admins remove members, not other admins).
- Deleting a role used by members or unexpired pending invitations → 422 with counts ("reassign first"). Expired invitations lose their role (`role_id` null) and can't be accepted.
- A `role_id` from the client is only a reference. It is looked up in the current organization's roles (scoped), so another org's role id → 422.

## Backend (`api/`)
1. **Migration (new, nothing committed gets edited)**:
   - `roles`: id, organization_id (FK cascade), name, is_owner bool, permissions jsonb, timestamps. Unique `(organization_id, lower(name))`, partial unique: one `is_owner` per org.
   - Add `role_id` to `organization_user` and `organization_invitations` (FK, `restrictOnDelete`, indexed).
   - Backfill: for each org, create Owner, Admin and Member with permissions matching today's table, then map the old `role` strings to `role_id`. Make `role_id` NOT NULL and drop the old `role` columns. `down()` reverses it.
2. **`Role` model** with `BelongsToOrganization`, `permissions` cast to a collection of `Permission`, plus `has(Permission)` and `isSubsetOf(Role|array)`. `Membership` and `OrganizationInvitation` get `role()` belongsTo. Remove the `OrganizationRole` enum.
3. **`CurrentOrganization`**: `role()` returns the `Role` model (loaded with the membership). Add `can(Permission): bool` and `permissions(): array`. `set()`/`runAs()` take `?Role`.
4. **Actions**: `CreateDefaultRoles` (used by `CreateOrganization` and the migration's defaults, one source of truth), `CreateRole`, `UpdateRole`, `DeleteRole` (row lock + usage check). Update `ChangeMemberRole`, `InviteMember`, `AcceptInvitation`, `RemoveMember` and `EnsuresAnOwnerRemains` to use `role_id`/`is_owner`.
5. **Policies**: `RolePolicy` (viewAny: any member; create/update/delete: `roles.manage` + subset rules). Rewrite `ProjectPolicy`, `MembershipPolicy` and `OrganizationInvitationPolicy` to work from permissions using the rules above.
6. **Endpoints** (`auth:sanctum` + `organization` middleware, like members):

   | Endpoint | Notes |
   |---|---|
   | `GET /api/permissions` | Catalogue with group + label, for the UI |
   | `GET /api/roles` | Current org's roles with member counts |
   | `POST /api/roles` `{name, permissions[]}` | `roles.manage` |
   | `PATCH /api/roles/{role}` `{name, permissions[]}` | 404 if outside the org; 403 for owner role / escalation |
   | `DELETE /api/roles/{role}` | 422 if in use |
   | `PATCH /api/members/{user}` `{role_id}` | changed from `{role}` |
   | `POST /api/invitations` `{email, role_id}` | changed from `{role}` |

   Form Requests validate `permissions.*` with `Rule::enum(Permission::class)` and `role_id` with a scoped exists check. **`RoleResource`**: `{id, name, is_owner, permissions, members_count}`. `MemberResource`/`InvitationResource`/`InvitationPreviewResource` return `role: {id, name, is_owner}`. `OrganizationResource` adds the current user's `permissions` array next to `role`.
7. **Tests (Pest)**: role CRUD permission matrix; subset/escalation cases (can't grant a permission you lack, can't edit your own role, can't touch the owner role); delete in-use → 422; every rewritten policy keeps its old matrix with the default roles (existing tests updated from `role` to `role_id`, same expectations); a custom "Viewer" role can read but not create or edit projects; new orgs get the three default roles. **Cross-org**: org A can't list, read, edit, delete or assign org B's roles (404/422), and can't invite with org B's `role_id`.

## Frontend (`web/`)
8. **Types and stores**: `Role`, `Permission` types; `role` fields become `{id, name, is_owner}`; the organization store keeps `permissions` for the current org.
9. **`useOrganizationPermissions()`** rewritten around `can(permission)` and the same subset rules, used only to show or hide controls.
10. **`/roles` page** (header link next to Members): roles table (name, member count, permission summary, owner role marked built-in). With `roles.manage`: "New role" and Edit in a `UModal` with a `UCheckbox` grid grouped by area (permissions you lack are disabled), Delete with a confirm modal. Loading, empty and error states; reloads when the org is switched.
11. **`/members`**: the role select and invite form use `GET /api/roles`, limited to assignable roles. **Projects pages**: edit/delete buttons gated by the new permissions.

## Out of scope
Per-project or per-resource permissions, more than one role per member, an audit log of role changes, platform super-admin.

## Verification
- `/verify` (Pint, `php artisan test`, ESLint, typecheck, `npm run build`).
- Run the migration against the local DB with existing orgs and check members keep their roles (`php artisan migrate`, then `/api/members`).
- `/e2e-smoke` on :8011/:3011: owner creates "Viewer" (projects.view only) → assigns it to a member → that member sees projects but no New/Edit/Delete, and the API returns 403 on direct calls → admin can't create a role with `roles.manage`-level permissions they lack → can't delete a role in use → org B's role id is rejected.
- Reviewers in parallel: code-reviewer, security-auditor, tenant-isolation-reviewer, database-reviewer, ui-reviewer (`/roles`, `/members`).
- Commits: `feat(api): add custom roles and permissions`, `feat(web): add roles page and permission-based controls`, then plan → Done. Update `CLAUDE.md` (multi-org model section: roles are per-org with permissions) and `README.md` features in the same commits.

## Outcome
- 228 Pest tests (32 new, all existing ones kept with the same expectations on the default roles). Mutation checks, each caught by at least one test: editing your own role, creating or updating without the subset check, assigning while ignoring the target's or the new role, inviting any role, unscoped `role_id` validation, revoking any invitation, unescaped names in the invitation email.
- Migration tested on a copy of the dev database (migrate → every membership and invitation keeps its role, each organization has 3 roles → rollback restores the old strings → migrate again), then run on the dev database.
- End-to-end on spare ports (33 API checks): owner creates Viewer → invites through the real email link → viewer reads projects but gets 403 on create/update/delete, roles and invitations → admin can't manage roles or invite owners → in-use role can't be deleted → a custom "Role manager" can only grant what it has and can't edit its own role or Admin → org B gets 404 on A's roles and 422 when assigning or inviting with them. Browser check (ui-reviewer, Playwright) passed for /roles, /members and /projects as owner, role manager and viewer, at 390px and with the keyboard.
- Reviewers: code, security, tenant isolation, database, UI. No high findings. Fixed from their reports:
  - security: revoking or replacing an invitation for a role you don't cover; peer role editing (strict subset); Markdown injection of org, inviter and role names in the invitation email; project update/delete now also need `projects.view`.
  - tenant isolation: test for a member of both organizations acting in A; database test for the invitation foreign key; `CurrentOrganization::set()` refuses another organization's role.
  - database: owner rows locked in a fixed order (no deadlock when two owners act on each other); duplicate-name race returns 422, not 500; membership foreign key is `NO ACTION` so deleting an organization doesn't depend on cascade order.
  - code: error states for roles and the permission catalogue on /roles and /members.
  - UI: a hydration mismatch on /roles and /members (the plan 07 follow-up) came from the empty header of the actions column; it now uses an `sr-only` "Actions" header like /projects.
- Changed from plan:
  - `OrganizationRole` is replaced by `DefaultRole` (Owner, Admin, Member names and default permissions), used by `CreateDefaultRoles`, the factory and tests. The migration keeps its own snapshot of the defaults so later changes don't alter it.
  - No `CurrentOrganization::permissions()`: resources map `Role::grants()` directly.
  - Invitation foreign key is `ON DELETE SET NULL (role_id)` (needs PostgreSQL 15+; local is 18) instead of restrict.
  - The invitation email says "with the **X** role" instead of "as a/an x".

## Follow-ups (not in this plan)
- **Pending invitations outlive the inviter's permissions:** an invitation stays valid after the inviter is demoted or removed. Re-check at accept time, or revoke on demotion. Files: `Actions/Organizations/AcceptInvitation.php`.
- **Role deleted while being assigned:** the losing request gets a 500 (foreign key violation) instead of a 422. Files: `ChangeMemberRole`, `InviteMember`, `AcceptInvitation`.
- **Validation before authorization:** forbidden role requests with invalid input get a 422 before the 403 (same pattern as the other endpoints). File: `Http/Requests/Roles/RoleRequest.php`.

