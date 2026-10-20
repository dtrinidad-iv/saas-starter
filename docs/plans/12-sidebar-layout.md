# 12 — Sidebar layout

**Status:** Done
**Commits:** `3ca3de3` (web)

## Context
The app uses a top `UHeader` (logo, org switcher, nav links, account menu). Navigation will grow, so replace it with a dashboard-style left sidebar whose footer holds the account menu, a color mode switch and the app version / source link. Decisions made: **replace** the header (no top header on desktop), footer = **account menu + color mode + version/links**.


## Design (web only, no API changes)

### `web/app/layouts/default.vue` — rewrite
- `UDashboardGroup unit="rem"` > `UDashboardSidebar` (`v-if="auth.user"`, `collapsible`, `resizable`, `v-model:open="menuOpen"`, `:ui="{ footer: 'border-t border-default' }"`) + `UDashboardPanel`.
  - Group persists collapsed/size in a cookie by default, so SSR renders the right width.
  - One `<slot />` in a fixed place (inside the panel body), so clearing the session (logout, 401) never remounts the page.
- Sidebar `#header="{ collapsed }"`: app name link to `/` (`i-lucide-layers` icon + "SaaS Starter"; icon only when collapsed).
- Sidebar `#default="{ collapsed }"`: `<OrgSwitcher :collapsed />` first, then `navigation` in `UNavigationMenu orientation="vertical" :collapsed tooltip`, each item with an `aria-label` (collapsed links show icons only). Adds a **Dashboard** item (`/`, always shown); the org items and the `can('audit_log.view')` gate are unchanged.
- Sidebar `#footer="{ collapsed }"`: `<UserMenu :collapsed />` (account menu + version/Source line).
- `UDashboardPanel`: `#header` = `UDashboardNavbar`, shown at every width:
  - left: mobile sidebar toggle (sidebar is a slideover below `lg`), `UDashboardSidebarCollapse` on `lg+` so a collapsed sidebar can be expanded without dragging, and "SaaS Starter" as a non-heading title below `lg` (pages keep their own `<h1>`).
  - right: `<UserMenu header />`, the same account dropdown (user asked for it in both places).
- `#body` = `<slot />` in a `max-w-6xl` wrapper.
- Keep the `watch(organizations.current?.id)` that closes the mobile menu after a switch. `autoClose` handles route changes.

### `web/app/components/OrgSwitcher.vue`
- Add `collapsed?: boolean` prop. Collapsed: square icon button (`i-lucide-building-2`) with a tooltip of the organization name; `aria-label="Organization: {name}"` always. Expanded: full-width (`block`, drop `max-w-56`), label truncates. Dropdown/modal logic unchanged.

### New `web/app/components/UserMenu.vue`
- Props `collapsed` and `header`. `header` renders a compact trigger (name hidden below `sm`, opens downward, aligned end) and no version line.
- Moves the account `UDropdownMenu` out of the layout. Button: `i-lucide-circle-user` + user name (name hidden when collapsed, `aria-label` kept), `block w-full` ghost, opens upward (`content: { side: 'top', align: 'start' }`).
- Items: email label; **Appearance** submenu with Light / Dark / System checkbox items bound to `useColorMode().preference` (color mode is built into `@nuxt/ui`, no new dependency); Log out (existing `logout()` → `/login`).
- Under the button, when not collapsed: small muted line `v{appVersion} · Source` (Source → `https://github.com/dtrinidad-iv/saas-starter`, opens in new tab).

### Version
- `web/package.json`: add `"version": "0.1.0"`.
- `web/nuxt.config.ts`: `runtimeConfig.public.appVersion` from `package.json`'s version (import the JSON). No env key needed.

### Copy / docs
- `web/app/pages/index.vue:54`: "switcher in the header" → "switcher in the sidebar".
- `CLAUDE.md`: replace "The org switcher lives in the main layout header" with a line describing the sidebar: switcher at the top, navigation, account menu (appearance, log out) and app version in the footer.
- `README.md:87`: "Org switcher in the header" → "in the sidebar".

## Files
- Modify: `web/app/layouts/default.vue`, `web/app/components/OrgSwitcher.vue`, `web/app/pages/index.vue`, `web/nuxt.config.ts`, `web/package.json`, `CLAUDE.md`, `README.md`, `docs/plans/README.md`
- New: `web/app/components/UserMenu.vue`, `docs/plans/12-sidebar-layout.md`
- Check the Nuxt UI MCP for `DashboardGroup`, `DashboardPanel`, `DashboardNavbar` and `NavigationMenu` (`collapsed`, `tooltip`) props before writing.

## Verification
- `/verify` (lint, typecheck, `npm run build`, Pint + Pest still green; no API changes).
- `/e2e-smoke` servers on :8011/:3011, then `ui-reviewer` (quick mode) with checks: desktop sidebar expanded and collapsed (persists across reload), nav links + `aria-current`, audit log link hidden for a Member, org switch from sidebar (expanded, collapsed, mobile slideover closes after switch), account menu: appearance switch persists, log out works, 390px: slideover toggle in navbar, no horizontal scroll, keyboard focus through sidebar, no console/hydration errors.
- Reviewers in parallel: `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer` (+ `ui-reviewer` above).
- Commit: `feat(web): replace header with collapsible sidebar`, then mark plan Done.

## Follow-ups
- Logout: the sidebar disappears for about one frame before `/login` renders, because `auth.user` clears before navigation finishes.
- A 401 on `GET /api/projects?page=1` showed twice in e2e browser runs, each time on the first load right after login or logout. It did not reproduce on later attempts. It may predate this change, since login is untouched; worth checking on its own.
