# 26 — Platform admin actions

**Status:** Done
**Commits:** `811a920` (api), `c45bcf1` (web), `3da9fe7` (docs)

## Goal
Let platform admins fix the support cases that plan 25 could only look at: reset a user's two-factor (lost phone), sign a user out everywhere, suspend or unsuspend a user, and make an existing member Owner of an organization whose owners are gone. Each action takes a written reason and is recorded in the platform log; the ownership change also shows in the organization's own audit log, as "Platform admin". This is the third plan in the admin series (plan 27 adds read-only impersonation).

Out of scope:
- Impersonation (plan 27).
- Acting on platform admins (yourself included): admins stay managed from the command line.
- Deleting users or organizations, editing profiles or emails, changing plans, budgets or credits.
- Suspending an organization (only users).
- Reading project content, chats or an organization's audit log (unchanged from plan 25).

## Decisions
- **Four actions** (your choice):
  - **Reset two-factor**: clears the secret, recovery codes, `two_factor_confirmed_at` and `two_factor_last_used_step`, deletes all their tokens, and emails them (`TwoFactorResetByAdmin`). A pending login challenge dies on its own, because `IssueTwoFactorChallenge::fingerprint` includes `two_factor_confirmed_at`.
  - **Sign out everywhere**: deletes all their tokens. No email.
  - **Suspend / unsuspend**: a new `users.suspended_at` (not fillable). Suspending deletes their tokens. No email; they find out when they log in.
  - **Make Owner**: gives an existing member the organization's built-in Owner role. Emails them (`MadeOwnerByAdmin`).
- **Never on platform admins, never on yourself.** Every user action refuses a target who is a platform admin (422 "Remove admin with `admin:revoke` first"), and Make Owner refuses the acting admin as the member. So an admin can't make themselves Owner of someone else's organization, and one admin can't lock out or demote another from the browser. An admin who lost their phone logs in with a recovery code and turns 2FA off themselves (which ends admin, as in plan 25).
- **A suspended user can't get or use a token:**
  - `LoginUser`: after the password check, refuse with "This account is suspended. Contact support." (only someone with the right password learns it).
  - `CompleteTwoFactorChallenge`: re-checks under the existing row lock and expires the challenge.
  - `ResetUserPassword`: refused with the same message, and `SendPasswordResetLink` sends nothing (same response as an unknown email).
  - Existing tokens: deleted on suspend, and `Sanctum::authenticateAccessTokensUsing` also rejects a suspended user's token (401), in case one is issued in a race.
  - Unsuspending only clears `suspended_at`; they log in again as normal.
- **Every action needs a reason**: `reason`, required, 10–500 characters, stored in the platform log `metadata`. Never sent to the user or the organization.
- **Write actions need a fresher code** than lookups: `two_factor_at` within `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES` (default 15), otherwise the existing 403 `two_factor_reauth`. `EnsurePlatformAdmin` takes a parameter (`platform-admin:action`) on the write routes. The client already knows how to ask for a code; the action modal asks inline and retries.
- **The organization's audit log shows "Platform admin"** (your choice):
  - New `audit_logs.by_platform_admin` boolean (default false). `RecordAudit` gets `bool $byPlatformAdmin = false`: when true it stores no `actor_id` and no `ip_address`, so the organization sees that support did it but not who or from where.
  - Make Owner records the existing `member.role_changed` (old role → Owner) this way. The full detail (admin, IP, reason) goes in the platform log.
  - `AuditLogResource` adds `by_platform_admin`; the audit log page shows "Platform admin" as the actor.
  - Saving that entry uses `RecordAudit`'s `organization:` parameter, which `runAs` the organization only around the insert (`BelongsToOrganization` sets `organization_id` from the current organization). That's the only `CurrentOrganization` use in `app/Actions/Admin`; the admin's own current organization and memberships are untouched. CLAUDE.md's platform admin section says so.
- **Locking**: each action locks the user row (`lockForUpdate`) and re-checks the target isn't an admin inside the transaction. Make Owner locks the organization row and the membership, in the same order as `DeleteAccount::lockedMemberships`, and is a no-op (no log entries) if they're already Owner.
- **Platform log actions** (`PlatformAuditAction`): `admin.two_factor_reset`, `admin.user_signed_out`, `admin.user_suspended`, `admin.user_unsuspended`, `admin.owner_assigned` (subject: the organization; metadata: member id, email and old role, plus the reason).
- **No new dependency.** **New `.env.example` key:** `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES`, with a comment (in `config/admin.php`).

## Backend (`api/`)
- Migrations (new):
  - `add_suspended_at_to_users_table`: timestamp, nullable.
  - `add_by_platform_admin_to_audit_logs_table`: boolean, default false.
- `User`: `suspended_at` datetime cast (not fillable), `isSuspended()`.
- `config/admin.php`: `action_two_factor_ttl_minutes`.
- `EnsurePlatformAdmin::handle($request, $next, ?string $level = null)`: `action` uses the shorter TTL.
- `AppServiceProvider`: `Sanctum::authenticateAccessTokensUsing` rejects tokens of suspended users.
- Actions in `app/Actions/Admin`, each in a transaction, refusing admins and the actor, and calling `RecordPlatformAudit` with the reason:
  - `ResetUserTwoFactor`
  - `SignOutUser`
  - `SuspendUser`, `UnsuspendUser` (refuse when already in that state, 422)
  - `MakeMemberOwner(Organization, User $member, string $reason)`: the member is looked up with `Membership` for that organization (404 when they aren't one); the Owner role is `Role::withoutGlobalScope(OrganizationScope::class)->where('organization_id', …)->where('is_owner', true)`.
- `RecordAudit`: `byPlatformAdmin` parameter, as above.
- Auth changes: `LoginUser`, `CompleteTwoFactorChallenge`, `ResetUserPassword`, `SendPasswordResetLink` (suspension, as above).
- Notifications: `TwoFactorResetByAdmin`, `MadeOwnerByAdmin` (organization name, link to the frontend).
- Form Request: `AdminActionRequest` (`reason`).
- Resources: `AdminUserResource` adds `suspended_at`; `AuditLogResource` adds `by_platform_admin`.
- Controller: `Admin\UserActionController` (`resetTwoFactor`, `signOut`, `suspend`, `unsuspend`) and `Admin\OrganizationOwnerController@store`. Each returns the refreshed `AdminUserDetailResource` / `AdminOrganizationDetailResource` (without writing a second "viewed" entry).
- Routes, inside the existing `admin` group, with `platform-admin:action`:
  - `POST /api/admin/users/{user}/two-factor/reset`
  - `POST /api/admin/users/{user}/sign-out`
  - `POST /api/admin/users/{user}/suspend`
  - `POST /api/admin/users/{user}/unsuspend`
  - `POST /api/admin/organizations/{organization}/owners` (`user_id` of an existing member; the organization comes from the route and the member must belong to it)

## Frontend (`web/`)
- Types: `AdminUser.suspended_at`, `AuditLogEntry.by_platform_admin`.
- `components/AdminActionModal.vue`: title, what will happen, a required reason `UTextarea` (with the 10–500 rule shown), and a confirm button (red for suspend and reset). On `two_factor_reauth` it swaps to a 6-digit `UPinInput`, posts to `reconfirm`, then retries the action. Validation errors show on the field.
- `admin/users/[id].vue`: an "Actions" card with Reset two-factor (only when 2FA is on), Sign out everywhere (only with devices), and Suspend or Unsuspend. Hidden for platform admins and yourself, with a line saying why. After an action the page shows the returned user and a toast.
- `AdminUserBadges.vue`: a red "Suspended" badge (users list and detail).
- `admin/organizations/[id].vue`: a "Make Owner" row action in the members table, for non-owners other than yourself.
- `pages/audit-log.vue`: "Platform admin" as the actor when `by_platform_admin`.
- `admin/audit-log.vue`: labels come from the API, so the new actions show without changes; the reason shows under the action.
- Login, 2FA challenge and reset password pages already show the API's message; check the suspended message reads well there.
- `README.md`: the "Run the platform" feature gets the actions; the API table gets the new routes.

## Tests
- Access, for every new route: guest 401; non-admin 404 (same body as unknown routes); admin without 2FA 403 `two_factor_required`; code older than the action TTL (but inside the lookup TTL) 403 `two_factor_reauth`; fresh code 200; unverified 403 `email_unverified`.
- Each action refuses a platform admin target and yourself, with nothing changed and nothing logged.
- `reason` missing, under 10 or over 500 characters → 422.
- Reset two-factor: fields cleared, all tokens gone, email sent, a pending challenge can't complete, logged with the reason; 422 when 2FA is off.
- Sign out: all their tokens gone (other users' stay), logged.
- Suspend:
  - Tokens deleted; a token created afterwards is rejected (401).
  - Login with the right password → 422 suspended; with a wrong password → the usual `auth.failed`.
  - A challenge started before the suspension can't complete.
  - Password reset refused, and no reset email is sent.
  - Unsuspend → can log in again. Both logged; suspending twice → 422.
- Make Owner:
  - The member gets the Owner role of **that** organization, with org A and org B seeded (B's Owner role is never used for A).
  - A user who isn't a member of the organization → 404; the acting admin as the member → 422; already Owner → no change, no entries.
  - The organization's audit log gets `member.role_changed` with `by_platform_admin: true`, no actor and no IP; no other organization gets an entry. The platform log has the admin, IP and reason.
  - The admin's current organization and memberships are unchanged; email sent to the member.
- `AuditLogResource`: `by_platform_admin` is false on normal entries; the reason never appears in an organization's audit log response.

## README screenshots
- Retake `docs/screenshots/admin-users.png` with one suspended demo user, so the Suspended badge shows.
- Retake `docs/screenshots/admin-organization.png`: the members table now has Make Owner.
- New `docs/screenshots/admin-user-actions.png`: a user's admin page with the Actions card and the reason modal open for "Reset two-factor".
- Captured as in plan 17 (light, 1280×800), with the demo admin set up as in plan 25. All `e2e-*` accounts are cleaned up afterwards.

## Verification
`verify` skill (Pint, Pest, lint, typecheck, build). `e2e-smoke` with `--mail`. Reviewers in parallel: code, security (suspension coverage of every way to log in, the self/admin rules), tenant isolation (Make Owner and the organization audit entry), database (two migrations, locking order), UI (the actions card, modal and badges).

Flows (for `flow-tester`), with an admin set up as in plan 25 (granted, logged in with a code):
1. Open a normal user with 2FA on → Actions card shows Reset two-factor, Sign out everywhere and Suspend. Open your own page or another admin → no actions, with a line saying why.
2. Reset two-factor with a 5-character reason → the reason field shows the error. With a proper reason → done; the user's badges lose 2FA, they get the "two-factor was reset" email, their logged-in browser is sent to log in, and they log in with just a password.
3. Suspend the user → their browser is logged out; logging in with the right password says the account is suspended; "forgot password" sends no email (check the mail log). Unsuspend → they can log in.
4. Sign out everywhere on a user logged in on two browsers → both are sent to log in.
5. On an organization, Make Owner on a Member → their role shows Owner, they get the email, and the organization's audit log (as that member) shows "Platform admin changed a member's role". The platform log shows the admin and reason.
6. Wait past the action TTL (or set `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES=1` for the run) and try an action → the modal asks for a code, then completes the action. Lookups still work without a new code.
7. As a normal user, `POST /api/admin/users/{id}/suspend` with curl → 404, and nothing changes.

## From the reviews (done here)
- **Lock order** (database review): `ChangeMemberRole` and `RemoveMember` now lock the organization row first (`EnsuresAnOwnerRemains::lockOrganization`), like `DeleteAccount`, `AcceptInvitation` and `MakeMemberOwner`. Their audit insert takes a key-share lock on that row through its foreign key, so locking it later could deadlock (this already applied with `DeleteAccount`). `MakeMemberOwner` reuses the trait's `lockOwnerRows` / `lockedRole`.
- **Make Owner refuses any platform admin**, not only yourself, so two admins can't make each other Owner (security review).
- **Wrong method or a plain OPTIONS on `/api/admin/*`** answered 405 / 200 with the allowed methods, before any middleware, which showed the area exists (already true for plan 25's routes). Both now get the same 404 as an unknown route (security review).
- The organization name is Markdown-escaped in the Make Owner email (security review).
- `AdminActionModal`: the code step shows loading while the action is retried, and server reason errors survive the step change (code review).
- The Make Owner column had an empty header, which caused a hydration mismatch on the organization page (UI review); it now has a screen-reader-only "Actions" header.

## Follow-ups
- Sign out everywhere and Reset two-factor can lose a race with a login that is issuing a token at that moment (`IssueToken` runs after the user row lock). Suspension is covered by the Sanctum check; the others could store a `tokens_revoked_at` that the Sanctum check compares with the token's `created_at`.
- `OrganizationInvitationMail` puts the organization name into Markdown unescaped, as Make Owner did before the fix.
- `npm audit`: 12 advisories in build and dev tooling (glob packages, node-forge via listhen, esbuild), none reachable at runtime; fixing them needs a Nuxt upgrade.

## Commits after approval
1. `feat(api): platform admin actions on users and organizations`
2. `feat(web): admin actions with reasons and inline two-factor`
3. `docs: platform admin actions in the README and CLAUDE.md, with a screenshot` (CLAUDE.md: the reason and action TTL, never on admins or yourself, suspension checks in every login path, `by_platform_admin` entries and the `runAs` exception)
4. This plan → Done.
