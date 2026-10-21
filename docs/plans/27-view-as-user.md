# 27 — View as user (read-only impersonation)

**Status:** Done
**Commits:** `0473b5e` (api), `5a4efd8` (web), `13471fc` (docs)

## Goal
Let a platform admin open the app as a user, read-only, to see what they see when they report a problem ("I can't find the billing page", "my role looks wrong"). The admin gives a reason and a fresh code; the user gets an email, and each organization the admin looks at gets a "Platform admin viewed as …" entry in its audit log. Nothing can be changed during the session, and project content (descriptions, summaries, chats) stays hidden, as in plans 25 and 26. This is the last plan in the admin series.

Out of scope:
- Write actions as the user (it's read-only, with no "act as" mode).
- Project content, summaries and chats (your choice). The project list shows names only.
- A consent setting on the user's side (your choice: reason, code and email instead).
- Viewing as a platform admin, or as yourself.
- Hiding edit buttons page by page. The API refuses the write, and the page shows its message.

## Decisions
- **A separate, short-lived token for the user.**
  - `POST /api/admin/users/{user}/impersonate` issues a Sanctum token whose tokenable is the **user**, with a new `personal_access_tokens.impersonator_id` (FK to users, `cascadeOnDelete`) and `expires_at` set to now + `ADMIN_IMPERSONATION_MINUTES` (default 30). It isn't renewed.
  - The admin's own token is untouched. The client keeps it aside and goes back to it when the session ends.
  - The impersonation token never gets `two_factor_at`, so it can't reach admin routes, and the target is never an admin anyway.
- **The Sanctum check rejects an impersonation token (401)** when its admin is no longer a platform admin, has 2FA off, or is suspended, or when the target became an admin. This sits in the existing `Sanctum::authenticateAccessTokensUsing`, next to the suspended-user check. A suspended target is already rejected there.
- **Read-only is enforced on the server, for every route by default.**
  - A new middleware `ReadOnlyImpersonation` (alias `impersonation`) sits in the `auth:sanctum` group itself, so new routes are covered without anyone remembering it. For an impersonation token, anything other than GET/HEAD gets 403 `impersonation_read_only`.
  - Two exceptions: `POST /api/logout` (ends the session) and `POST /api/organizations/{id}/switch` (see below).
  - Streams (writing help, chat) are POSTs, so no AI is called and nothing is spent.
- **Project content stays hidden** (your choice):
  - A route middleware `not-impersonating` returns 403 `impersonation_hidden` on `GET projects/{project}/summary` and `GET projects/{project}/chat`.
  - `ProjectResource` returns `description: null` and `content_hidden: true` under impersonation.
  - `AuditLogResource` hides project labels and changes as it does for a viewer without `projects.view`.
  - Device list: impersonation tokens never show in the user's devices (`DeviceController@index`, `RevokeDevice`) or in the admin's "last active" for them (`ShowUser`). `GET /account/devices` is also `not-impersonating`, because it lists the user's IPs.
  - `App\Support\Impersonation` answers `active()` and `token()` from the current token, so these checks live in one place.
- **Switching organizations doesn't touch the user's row.** A new `personal_access_tokens.organization_id` (nullable FK, `cascadeOnDelete`) holds the organization the session is looking at. It starts as the user's current one. `User::activeOrganization()` reads it for an impersonation token (still checking membership), and `SwitchOrganization` writes it instead of `users.current_organization_id`. The user's own current organization never changes.
- **Who sees what:**
  - **The user** gets an email at the start (`ViewedBySupport`: "Support viewed your account read-only on …, for up to 30 minutes. If you didn't contact support, reply to this email."). The reason isn't included, as in plan 26.
  - **The organization:** a new `AuditAction::SupportViewedAs` (`support.viewed_as`, "Platform admin viewed the app as a member"), recorded with `RecordAudit(..., byPlatformAdmin: true)`, subject the user. It's written in the organization the session starts in, and in each organization switched to, once per session per organization (tracked on the token, a `jsonb viewed_organization_ids`). That's the only write an impersonation request makes besides Sanctum's `last_used_at`.
  - **Platform log:** `admin.impersonation_started` (reason, token id, expiry) and `admin.impersonation_ended` (on logout or Stop; an expired session just ends, and the log keeps the expiry from the start entry).
- **Starting needs a fresh code** (`platform-admin:action`), a reason (`AdminActionRequest`), and the same target rules as plan 26 (`ActsOnUsers`): never an admin, never yourself. Also refused for a suspended user (422, since their token would be rejected anyway) and for a user with no organizations (422 "Nothing to view").
- **Expired tokens** are deleted by `sanctum:prune-expired --hours=24`, scheduled daily.
- **Throttles** are counted against the user while impersonating (e.g. `throttle:stripe` for invoices). That's acceptable for a 30-minute read-only session.
- **No new dependency.** **New `.env.example` key:** `ADMIN_IMPERSONATION_MINUTES`, with a comment (in `config/admin.php`).

## Backend (`api/`)
- Migration (new): `add_impersonation_columns_to_personal_access_tokens`, with:
  - `impersonator_id`: nullable FK to users, `cascadeOnDelete`, indexed
  - `organization_id`: nullable FK to organizations, `cascadeOnDelete`
  - `viewed_organization_ids`: jsonb, nullable
- `config/admin.php`: `impersonation_minutes`.
- `App\Support\Impersonation` (stateless, reads the current token): `active()`, `token()`.
- `App\Actions\Admin\StartImpersonation(User $admin, User $target, string $reason)`: in a transaction, it locks the target, re-checks the rules, creates the token (through a new `IssueToken::impersonation()`, so tokens still come from one place), records the start in the platform log and the organization audit entry, and queues the email. Returns the plain token and the expiry.
- `App\Actions\Admin\EndImpersonation`: deletes the token and records `admin.impersonation_ended` (actor: the impersonator). `AuthController@logout` calls it for an impersonation token.
- `App\Actions\Admin\RecordImpersonatedView`: the per-organization audit entry, once per session (locks the token row).
- `SwitchOrganization`: writes the token's `organization_id` under impersonation, then calls `RecordImpersonatedView`.
- `User::activeOrganization()`: the token's organization under impersonation.
- Middleware: `ReadOnlyImpersonation` (in the `auth:sanctum` group, with an allow-list of the two routes above, by route name) and `NotImpersonating` (alias `not-impersonating`).
- `AppServiceProvider`: the extra Sanctum checks.
- `UserResource`: `impersonated_by: { name, expires_at } | null`. Only the admin's name, never their email.
- `ProjectResource` and `AuditLogResource`: hiding, as above. `DeviceController`, `RevokeDevice` and `ShowUser` leave out impersonation tokens.
- Enums: `PlatformAuditAction::ImpersonationStarted` and `ImpersonationEnded`, `AuditAction::SupportViewedAs`, each with a label.
- Notification `ViewedBySupport`.
- Controller: `Admin\ImpersonationController@store`, returning `{ token, expires_at }` (the client then loads `/api/me` with the new token).
- Route, in the admin `platform-admin:action` group: `POST /api/admin/users/{user}/impersonate`.
- `routes/console.php`: `sanctum:prune-expired --hours=24`, daily.

## Frontend (`web/`)
- `stores/auth.ts`:
  - `startImpersonation(userId, reason)` moves `auth_token` into a second cookie, `admin_token` (same options, kept 12 hours instead of 30 days), sets the new token, fetches `/me` and goes to `/`.
  - `stopImpersonation()` posts `/api/logout` with the impersonation token, puts `admin_token` back, fetches `/me`, resets the organization store and goes to `/admin/users/{id}`.
  - A 401 while a session is open (e.g. after expiry) restores `admin_token` instead of logging out, and shows "The support view ended." (see `useApi.ts` below).
  - `isImpersonating` comes from `user.impersonated_by`.
- `composables/useApi.ts`: a 401 while a session is open (it expired or was ended) goes back to the admin's account (`stopImpersonation({ expired: true })` in the browser, `restoreAdminToken` during SSR) instead of logging out. No extra toast for `impersonation_read_only`: pages already show the API's message ("You're viewing as this user, read-only…") where a save fails, and a second toast would repeat it.
- `components/ImpersonationBanner.vue`: a `UBanner`-style bar at the top of the default layout, "Viewing as **Jane Doe** (read-only) · ends at 14:30", with a "Stop viewing" button. On every page while impersonating.
- `UserMenu.vue`: while impersonating, "Log out" becomes "Stop viewing".
- `admin/users/[id].vue`: "View as user" in the Actions card, through `AdminActionModal` (reason, plus a code when needed). It's hidden where the other actions are (admins, yourself), and also for suspended users and users with no organizations.
- Projects: the project page shows "Content hidden in the support view" instead of the description, summary card and chat when `content_hidden`.
- `pages/account.vue`: the devices section shows "Hidden in the support view" on `impersonation_hidden`.
- `pages/audit-log.vue`: the new action's label comes from the API, and the actor shows as "Platform admin" (`by_platform_admin`).
- `README.md`: "Run the platform" gets "view as a user, read-only"; the API table gets the new route.

## Tests
- Access to `POST /api/admin/users/{user}/impersonate`: guest 401; non-admin 404 (same body as unknown routes); no 2FA 403 `two_factor_required`; a code older than the action TTL 403 `two_factor_reauth`; unverified 403 `email_unverified`; reason rules 422.
- Targets: refused for a platform admin, yourself, a suspended user and a user with no organizations, with nothing created or logged.
- Start: the token belongs to the user, with `impersonator_id`, `expires_at` and the organization set; the email is sent (without the reason); the platform log has the admin, IP and reason; the starting organization's audit log has `support.viewed_as` with `by_platform_admin: true`, no actor and no IP.
- Read-only, as a dataset over every non-GET route in `routes/api.php` (built from the route list, so new routes are tested automatically): 403 `impersonation_read_only`, and nothing in the database changes. Logout and switch are the exceptions.
- Hidden: summary and chat 403 `impersonation_hidden`; `GET /projects` and `/projects/{id}` have no description; the audit log hides project entries' labels and changes; `/account/devices` 403. The same requests with the user's own token still see everything.
- Switch: under impersonation it changes the token's organization, not `users.current_organization_id`; a non-member organization 404s; the audit entry is written once per organization per session. The org A/B check: switching to an organization the user isn't in, or reading B's projects while in A, fails.
- End: logout deletes only the impersonation token, logs `admin.impersonation_ended`, and leaves the admin's and the user's own tokens alone. An expired token gets 401.
- Sanctum check: the token gets 401 after `admin:revoke`, after the admin turns 2FA off, or after the target is suspended.
- Devices and admin view: impersonation tokens don't show in the user's devices, can't be revoked by the user, and don't change "last active".
- `/me` under impersonation: `impersonated_by.name`, no admin email, `is_platform_admin: false`.
- Prune: the scheduled command is registered.

## README screenshots
- New `docs/screenshots/admin-view-as.png`: the projects page viewed as a demo user, with the banner and a project whose content is hidden.
- Retake `docs/screenshots/admin-user-actions.png`: the Actions card now has "View as user".
- Captured as in plan 17 (light, 1280×800), with the demo admin set up as in plan 25. All `e2e-*` accounts are cleaned up afterwards.

## Verification
`verify` skill (Pint, Pest, lint, typecheck, build). `e2e-smoke` with `--mail`. Reviewers in parallel: code, security (read-only coverage of every route, the token rules, cookie handling), tenant isolation (the token's organization, switching, hidden project content), database (the migration, the token row lock), UI (banner, toast, hidden content, the 390px layout with the banner).

Flows (for `flow-tester`), with an admin set up as in plan 25 (granted, logged in with a code) and a user in two organizations with a project:
1. On the user's admin page, View as user with a short reason → reason error. With a proper reason → the app opens as the user with the banner, their name in the account menu, and their current organization.
2. Open the project → its name shows, and "Content hidden in the support view" replaces the description, summary and chat. Try Edit and save → the "Read-only" toast, and nothing changes (check later as the user).
3. Switch to the user's other organization → its pages show. Log in as the user in another browser → their current organization is still the first one, and they have the "support viewed your account" email.
4. As the owner of each organization, the audit log shows "Platform admin viewed the app as a member" once per organization; the platform log shows the start with the reason.
5. Stop viewing → back on the user's admin page as the admin, with the admin sidebar, and the platform log shows the end.
6. Start again and wait past the expiry (or run with `ADMIN_IMPERSONATION_MINUTES=1`) → the next request goes back to the admin with "The support view ended."
7. As the user, the devices list never shows the support session.
8. With curl and the impersonation token: `DELETE /api/projects/{id}`, `POST /api/invitations` and `PATCH /api/account` → 403 `impersonation_read_only`; `GET /api/admin/users` → 404.

## From the reviews (done here)
- **Lock order** (database review): `StartImpersonation` locks the starting organization's row before the user, and `RecordImpersonatedView` locks the organization before the token, like plan 26's actions. Moving the session to another organization and its audit entry are now one transaction, so the session never moves without the entry.
- `personal_access_tokens.organization_id` is `nullOnDelete` (a deleted organization leaves the session with none, instead of deleting it); the starting organization is ordered by name, then id (database review).
- **Owners' audit log IPs** were shown in the support view; `AuditLogResource` now hides `ip_address` there too (security review).
- **401 handling** (code review): a 401 for a token that's no longer current (parallel requests after the session ended) is ignored, instead of logging the admin out. A reload after expiry put the admin's token back during SSR but then called `useApi` without a Nuxt context; `fetchMe` now retries with the same instance, and the browser shows "The support view ended" and leaves the user's pages (`supportViewEnded`).
- The banner showed the end time in the server's time zone first (hydration mismatch); it now adds it once mounted (code review).
- A failed start ends the session it opened; Stop without `admin_token` logs out; `admin_token` lasts 12 hours, not 30 days; `clearNuxtData()` when switching identity; the email says changing the password ends a session (code, security and tenant reviews).
- Five more isolation tests: a user in both organizations, a user removed mid-session (fails closed), the session and the user's own token kept apart, the admin's own organization untouched, and a stale current organization at the start (tenant isolation review).

## Follow-ups
- If `/api/logout` fails on Stop, the session token stays valid on the server until it expires (the browser drops it), and no "ended" entry is logged.
- The user can't end a support session from their devices list (it's hidden there); changing their password does.
- Stop swaps to the admin before leaving the user's page, so that page can refetch once as the admin before the navigation (it has to: admin pages need `is_platform_admin`).

## Commits after approval
1. `feat(api): read-only view as a user for platform admins`
2. `feat(web): view as user with a banner and read-only toasts`
3. `docs: view as user in the README and CLAUDE.md, with screenshots` (CLAUDE.md, Platform admin section: the impersonation token rules, read-only by default in the `auth:sanctum` group, `not-impersonating` for anything that shows project content or private account data, and the per-organization audit entry)
4. This plan → Done.
