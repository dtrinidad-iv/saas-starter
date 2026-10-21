# 21 — Choose an organization after login

**Status:** Done
**Commits:** `b133be1` (web)

## Goal
Today, logging in drops the user into whatever `users.current_organization_id` was last set to, silently. Someone in several organizations (e.g. a consultant in two client workspaces) can start working in the wrong one without noticing. Instead, when the user belongs to **more than one** organization, login shows a "Choose an organization" step. If they have one (or none), nothing changes.

No API change is needed: `GET /api/organizations` (list + role) and `POST /api/organizations/{id}/switch` (checks membership on the server) already do this. The server stays the source of truth for the current organization.

## Decisions
- **New page `web/app/pages/select-organization.vue`**: `auth` layout, `auth` middleware. A `UCard` titled "Choose an organization" with one button row per organization (name + role name, like `OrgSwitcher`), the current one marked "Last used". Clicking one:
  - if it's already current → just navigate;
  - else `organizations.switchTo(id)` (existing store action), then navigate.
  - Navigates to `safeRedirect(route.query.redirect)`. Errors toast and refetch the list (same as `OrgSwitcher.switchTo`).
  - Loads the list with `organizations.fetch()` if the store is empty (full reload of the page). If the list has ≤1 organization, it redirects straight on (so the URL can't strand anyone).
  - Footer link "Log in with another account" → `auth.logout()`.
- **`web/app/pages/login.vue`**: after `auth.login()`, `await organizations.fetch()` (the auth plugin only loads organizations on startup, not after a client-side login — this also fixes an empty switcher right after login). Then:
  - `organizations.length > 1` and the redirect isn't an invitation link (`/join/...`, where accepting sets the organization anyway) → `navigateTo({ path: '/select-organization', query: { redirect } })`;
  - otherwise → the redirect as today.
  - If the fetch fails, fall through to the redirect (don't block login).
- **Register** is unchanged (a new account has exactly one organization).
- Not remembered ("don't ask again") — out of scope; can be added later.

## Files
- `web/app/pages/select-organization.vue` (new)
- `web/app/pages/login.vue`
- `README.md`: "What it does" step 1 + "API and pages" row (`/select-organization`), screenshot line
- `docs/screenshots/select-organization.png` (new, plan-17 way: e2e servers, light, 1280×800, `e2e-*` accounts cleaned up)
- `docs/plans/21-choose-organization-after-login.md` + `docs/plans/README.md` index (written first, via `/plan-task`, Status Proposed → WIP)

Reuse: `useOrganizationStore().fetch/switchTo`, `useAuthStore().logout`, `safeRedirect`, `errorMessage`.

## Verification
- `/verify` (Pint, Pest — unchanged API, existing `SwitchOrganizationTest` covers membership checks — lint, typecheck, build).
- `/e2e-smoke` on :8011/:3011 with Playwright:
  - user in 2 orgs logs in → picker → picks the non-current one → sidebar shows it, `/api/me` agrees;
  - user in 1 org logs in → straight to `/`;
  - `/login?redirect=/projects` with 2 orgs → picker → lands on `/projects`;
  - invitation link `/join/{token}` while logged out → login → straight to the join page;
  - visiting `/select-organization` directly with 1 org → redirected.
- Reviewers in parallel: code-reviewer, security-auditor (redirect handling), tenant-isolation-reviewer, ui-reviewer (picker at 390px, keyboard focus).

## Found in review
- A picker URL as the redirect (e.g. opening `/select-organization` while logged out) showed the picker twice or left the user on it. Login no longer wraps a redirect that is already the picker, and the picker treats itself as a target as `/`.
- A failed navigation after a successful switch showed "Could not switch organization"; only the switch is in the try/catch now.
