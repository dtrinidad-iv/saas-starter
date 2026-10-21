# 25 — Platform admin foundation

**Status:** Done
**Commits:** `75df79e` (api), `1501e87` (web), `92aa2b4` (docs)

## Goal
Give the people who run the app (not an organization's owners) a read-only admin area, where they can look up any user or organization to help with support. Platform admins need two-factor turned on, and their token must have passed a code recently. Each lookup is recorded in a platform audit log. This is the second plan in the admin series: plan 26 adds admin actions (including "reset a user's 2FA"), and plan 27 adds read-only impersonation.

Out of scope:
- Any write action on users or organizations (plan 26).
- Impersonation (plan 27).
- Admin levels or roles. There is one flag: you are a platform admin or you aren't.
- Reading project content, chat messages or an organization's own audit log.
- A stats dashboard.

## Decisions
- **Admin is a flag on the user, set only from the command line** (your choice). `users.is_platform_admin` is not fillable, so no API request can set it. `php artisan admin:grant {email}` and `admin:revoke {email}` change it, and each one writes an entry in the platform log (with no actor). It has nothing to do with organization roles or `Permission`.
- **Two-factor is required, and the token must have passed it.**
  - Plan 24 kept the token you used while turning 2FA on, and that token was issued after a password only. So "2FA is on" is not enough on its own.
  - A new `personal_access_tokens.two_factor_at` column records when the token last passed a code. `CompleteTwoFactorChallenge` sets it on the token it issues after a code from the app (not a recovery code: those can be remade with the password alone). `ConfirmTwoFactor` does not set it, because a brand-new factor proves only the password (from the security review).
  - Admin routes need all of the following:
    - `is_platform_admin`;
    - `hasTwoFactorEnabled()`, otherwise 403 `two_factor_required`;
    - `two_factor_at` on this token within `ADMIN_TWO_FACTOR_TTL_MINUTES` (default 720, i.e. 12 h), otherwise 403 `two_factor_reauth`.
  - Turning 2FA off also clears `is_platform_admin`, recorded as `admin.revoked` with `{reason: two_factor_disabled}`. Otherwise a password alone could swap in a new authenticator. Only `admin:grant` makes them an admin again (from the security review).
  - `admin:grant` refuses a user without 2FA, so the factor exists before the grant.
- **Re-confirming with a code.** `POST /api/account/two-factor/reconfirm` (`code`, `throttle:6,1,account`) checks a TOTP code for the logged-in user and sets `two_factor_at` on the current token.
  - The user row is locked, and the code's step must be newer than `two_factor_last_used_step`, as at login.
  - Wrong codes count toward the same per-account limit as the challenge (`two-factor:user:{id}`).
  - It's an account endpoint, so any user with 2FA on can call it, but only admin routes care about the result. It takes no recovery code. If you lost your phone, a recovery code still logs you in, but not into the admin area. You'd set up 2FA again, which ends admin, and then need a new grant (plan 26 adds an admin reset).
- **Non-admins get 404 on `/api/admin/*`,** so the API doesn't confirm the area exists. The body is the router's own unknown-route 404. `EnsurePlatformAdmin` runs before `SubstituteBindings` (priority list), so missing and existing ids look the same. The `admin` limiter counts only admins, so non-admins never see a 429 (from the reviews). Admins who haven't met the 2FA rules get the 403 codes above, so the client can show the right next step.
- **Reading other organizations goes through explicit queries, never a current organization.**
  - Admin routes sit outside the `organization` middleware, and they never call `CurrentOrganization::set` or `runAs`.
  - Tenant-owned counts (projects, invitations) use `withoutGlobalScope(OrganizationScope::class)->where('organization_id', …)`, as `AiBudget::spent` already does.
  - Each of these is written once, inside `app/Actions/Admin`, so `tenant-isolation-reviewer` has one place to check.
- **Separate admin resources** (`App\Http\Resources\Admin\*`) list exactly which fields go out. Reusing `UserResource` or `OrganizationResource` could leak a field added to them later. Secrets never appear: 2FA secret, recovery codes, `stripe_id`, card details, tokens.
- **Platform audit log** (your choice: platform only, organizations don't see admin lookups).
  - A new table `platform_audit_logs`, not tenant-owned. It is append-only like `AuditLog` and pruned by `model:prune` after `AUDIT_RETENTION_DAYS`.
  - `App\Enums\PlatformAuditAction`: `admin.granted`, `admin.revoked`, `admin.user_viewed`, `admin.organization_viewed`.
  - A user or organization detail view writes one entry each time it's opened. List searches aren't logged, because the search text is usually someone's email address.
  - `App\Actions\Admin\RecordPlatformAudit`.
- **Search** uses `ILIKE` on name and email (users) or name (organizations), with `%` and `_` escaped. It is paginated 25 at a time, newest first, and the query is limited to 100 characters.
- **Throttle** `throttle:admin` (60 a minute per admin), and `verified` after the admin check.
- **No new dependency.** **New `.env.example` key:** `ADMIN_TWO_FACTOR_TTL_MINUTES`, with a comment (it goes in a new `config/admin.php`).

## Backend (`api/`)
- Migrations (all new):
  - `add_is_platform_admin_to_users_table`: boolean, default false.
  - `add_two_factor_at_to_personal_access_tokens`: timestamp, nullable.
  - `create_platform_audit_logs_table`, with these columns:
    - `id`
    - `actor_id`: nullable FK to users, `nullOnDelete`
    - `action`
    - `subject_type`, `subject_id`, `subject_label`
    - `metadata` (json, nullable)
    - `ip_address`
    - `created_at`

    Indexes on `id` and `created_at` (for pruning), and `(actor_id, id)`.
- `User`: `is_platform_admin` boolean cast (not fillable) and `isPlatformAdmin()`.
- `PlatformAuditLog` model: append-only (throws on update and delete, like `AuditLog`), `MassPrunable`, an `actor()` relation.
- `config/admin.php`: `two_factor_ttl_minutes`.
- Middleware `EnsurePlatformAdmin`, alias `platform-admin`: returns 404, 403 `two_factor_required` or 403 `two_factor_reauth`, in that order.
- `IssueToken::handle(User, ?string $deviceName, bool $twoFactor = false)` sets `two_factor_at`. `CompleteTwoFactorChallenge` passes true for an app code. `DisableTwoFactor` revokes a platform admin.
- Actions:
  - `app/Actions/Account/ReconfirmTwoFactor` (the code check, the per-account limiter and the token stamp).
  - `app/Actions/Admin/SearchUsers`.
  - `ShowUser`: the profile, flags, last active (newest `tokens.last_used_at`), the device count, and the organizations with the role name. It records `admin.user_viewed`.
  - `SearchOrganizations`: name, member count, plan key, created.
  - `ShowOrganization`, which returns:
    - name and created date;
    - plan and subscription status, from `Plans::for` and `$organization->subscriptions()`;
    - `AiBudget::summary`;
    - counts of members, projects and pending invitations;
    - the members with their roles (the first 100, by join date).

    It records `admin.organization_viewed`.
  - `ListPlatformAuditLog`: paginated, newest first.
- Commands: `GrantPlatformAdmin` (`admin:grant {email}`) refuses a user without 2FA; `RevokePlatformAdmin` (`admin:revoke {email}`).
- Form Requests: `AdminSearchRequest` (`q`: nullable string, max 100; `page`) and `ReconfirmTwoFactorRequest` (`code`: 6 digits).
- Resources in `Admin\`:
  - `AdminUserResource`
  - `AdminUserDetailResource`
  - `AdminOrganizationResource`
  - `AdminOrganizationDetailResource`
  - `PlatformAuditLogResource`
- `UserResource`: add `is_platform_admin` (bool), for the navigation.
- Controllers: `Admin\UserController` (index, show), `Admin\OrganizationController` (index, show), `Admin\AuditLogController` (index), and `TwoFactorController@reconfirm`.
- Routes:
  - `auth:sanctum`, `throttle:6,1,account`:
    - `POST /api/account/two-factor/reconfirm`
  - `auth:sanctum`, `verified`, `platform-admin`, `throttle:60,1,admin`, prefix `admin`:
    - `GET /api/admin/users`
    - `GET /api/admin/users/{user}`
    - `GET /api/admin/organizations`
    - `GET /api/admin/organizations/{organization}`
    - `GET /api/admin/audit-log`
- `routes/console.php`: add `PlatformAuditLog` to the `model:prune` models.

## Frontend (`web/`)
- Types: `User.is_platform_admin`, `AdminUser`, `AdminUserDetail`, `AdminOrganization`, `AdminOrganizationDetail` and `PlatformAuditLogEntry`.
- `composables/useAdminData.ts` (built instead of an `admin.ts` middleware plus `useAdminApi`, so "not found" shows inside the normal layout, the way the project page does it): loads an admin endpoint and returns failures as data.
  - A non-admin gets not found without calling the API.
  - A 404 becomes not found, and is also an HTTP 404 when rendered on the server.
  - `two_factor_required` / `two_factor_reauth` and other errors become states the page shows, with no redirect loop.
- `composables/useSearchPage.ts`: the search box and page, kept in `?q=&page=`, with a 300 ms debounce.
- `components/AdminGate.vue` shows what the page can't:
  - not found: "Page not found".
  - `two_factor_required`: "Turn on two-factor to use the admin area", with a link to `/account`.
  - `two_factor_reauth`: "Confirm it's you", a 6-digit `UPinInput` that posts to `reconfirm` and then retries.
  - any other error: an alert with Retry.
- Pages, all `middleware: 'auth'`:
  - `admin/users/index.vue`: a search field (debounced, kept in `?q=`), a `UTable` of name, email, verified, 2FA, organizations and created, and pagination.
  - `admin/users/[id].vue`: a profile card with badges (verified, 2FA, admin) and an organizations table linking to their admin pages.
  - `admin/organizations/index.vue`: search, and a table of name, members, plan and created.
  - `admin/organizations/[id].vue`: the plan and subscription, an AI budget meter, counts, and a members table linking to user pages.
  - `admin/audit-log.vue`: a table of when, admin, action, subject and IP.
  - Each one has loading, empty and not-found states.
- `layouts/default.vue`: when `auth.user.is_platform_admin`, a second `UNavigationMenu` labelled "Platform admin" (Users, Organizations, Platform log) appears below the organization links. It shows even when the admin has no current organization.
- `README.md`:
  - Features: a new item, "Run the platform", describing the admin area.
  - Setup: `php artisan admin:grant`.
  - The API and pages table gets the new routes.

## Tests
- Access, for every admin route:
  - a guest gets 401;
  - a non-admin gets 404;
  - an admin without 2FA gets 403 `two_factor_required`;
  - an admin whose token didn't pass a code (password-only token, a new 2FA setup, or a recovery-code login) gets 403 `two_factor_reauth`;
  - an admin whose token passed a code more than the TTL ago gets 403 `two_factor_reauth`;
  - an admin within the TTL gets 200;
  - an unverified admin gets 403 `email_unverified`.
- The admin flag:
  - `PATCH /api/account` with `is_platform_admin` doesn't change it, and neither does registering with it.
  - Turning 2FA off clears the flag (logged), and setting 2FA up again doesn't bring admin back.
  - A non-admin gets the same 404 body for admin pages, missing ids, existing ids and unknown routes, and never a 429.
- Tokens:
  - The challenge sets `two_factor_at`, and a plain password login (no 2FA) doesn't.
  - `ConfirmTwoFactor` and recovery-code logins don't stamp it.
- Reconfirm:
  - A right code stamps the current token and no others.
  - Reusing a code is refused.
  - A wrong code counts toward the per-account limit.
  - It's throttled.
  - It does nothing for a user without 2FA.
- Commands: grant and revoke change the flag and write platform log entries; an unknown email fails; grant refuses without 2FA.
- Search: finds by name or email, case-insensitive; `%` and `_` are taken literally; it's paginated; `q` over 100 characters gets 422.
- Isolation and data:
  - An admin with no current organization (or a current organization A) sees organization B's counts, members and plan.
  - The admin's own current organization and membership are unchanged afterwards.
  - The counts of projects and invitations are B's only, with A's data seeded alongside.
  - No response contains `two_factor_secret`, recovery codes, `stripe_id`, `pm_last_four`, token values or project content.
- Platform log:
  - The detail views write `admin.user_viewed` / `admin.organization_viewed` with the actor and IP.
  - List searches write nothing.
  - Entries can't be updated or deleted.
  - `model:prune` removes old ones.
  - No entries appear in any organization's `audit_logs`.

## README screenshots
- New `docs/screenshots/admin-organization.png`: an organization's admin page, with its plan, AI budget, counts and members, under a new "Platform admin" heading.
- New `docs/screenshots/admin-users.png`: the user search with results.
- Captured as in plan 17 (light, 1280×800). The demo admin is an `e2e-*` account granted with `admin:grant`, with 2FA turned on through the app using a code worked out from its setup key. All `e2e-*` accounts are cleaned up afterwards.

## Verification
`verify` skill (Pint, Pest, lint, typecheck, build). `e2e-smoke` with `--mail`. Reviewers in parallel: code, security, tenant isolation (the cross-organization reads), database (three migrations, search queries, counts), UI (the admin pages and sidebar).

Flows (for `flow-tester`):
1. Log in as a normal user and open `/admin/users` → 404 page, and no "Platform admin" section in the sidebar.
2. `admin:grant` an account without 2FA → refused ("needs two-factor turned on first"); the account still gets 404 on `/admin/users`.
3. Turn on 2FA, then `admin:grant` again → granted. On that same login, `/admin/users` shows "Confirm it's you"; entering a fresh code loads the user list.
4. Log out, log in with password + code, search for the second test user by part of their email → found. Open them → their organizations and roles are shown. The platform log lists "viewed user".
5. Open one of their organizations → plan, AI budget, counts and members are shown, and the organization's own audit log (as its owner) has no new entry.
6. Turn 2FA off → `/admin/users` gives "Page not found", the sidebar section is gone after a reload, and (after turning 2FA on again) the account isn't an admin until granted again.
7. Grant again, log in with a code, then `admin:revoke` → `/admin/users` gives 404, and the sidebar section is gone after a reload.
8. Load `/admin/users` and an organization page directly (typed URL, not by clicking) as the admin and as a normal user → the page as the admin, "Page not found" for the normal user; never a 500.

## Follow-ups
From the reviews, not done here:
- Record list searches and paging in the platform log too (without the search text), so paging through every user leaves a trace. Left out on purpose for now: search text is usually an email address.
- A shorter `ADMIN_TWO_FACTOR_TTL_MINUTES` default, and an idle timeout on admin tokens. Optionally, email the admin when a token is reconfirmed from a new IP.
- `clearNuxtData()` on logout, so admin data cached in the tab can't show in a later session.
- Trigram indexes (`pg_trgm`, a new extension) if user or organization search gets slow at hundreds of thousands of rows.
- Signed-out callers get 401 on admin routes but 404 on unknown paths, as on every logged-in route.

## Commits after approval
1. `feat(api): platform admins with two-factor and read-only lookups`
2. `feat(web): platform admin area`
3. `docs: platform admin in the README and CLAUDE.md, with screenshots` (CLAUDE.md gets a "Platform admin conventions" section: the flag only from the command line, the 2FA and token rules, explicit queries in `app/Actions/Admin`, admin resources, and the platform log)
4. This plan → Done.
