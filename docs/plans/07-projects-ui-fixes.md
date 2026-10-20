# 07 — Projects UI fixes (from ui-reviewer)

**Status:** Done
**Commits:** `5a6e31b` (web), `024d72b` (claude)

## Goal
Fix the problems `ui-reviewer` found in a real browser on the Projects pages and the header (see "Follow-up" in plan 06). There are 3 medium issues and 8 low ones.

Out of scope:
- new features;
- the Members page, apart from the shared header;
- a web test runner. It would be a new dependency (Vitest + Vue Test Utils), and it gets its own plan if you want it.

## Decisions
- **Header:** switch to Nuxt UI's `UHeader` and `UNavigationMenu`, so the mobile menu and the active link come from Nuxt UI (CLAUDE.md: Nuxt UI before custom components).
  - **Below `lg`:** the Projects/Members links and the org switcher move into the header's hamburger menu. The user menu stays in the header but shows only an icon, with an `aria-label`.
  - **`lg` and up:** the header looks the same as today.
- **Lost Save click:** the cause is blur validation clearing the server error while the mouse is pressed, which moves the button. `ProjectForm`'s `UForm` will validate on `input` only, plus on submit; the default is `input`, `blur` and `change`. Input validation runs with no delay and `eager-validation` on both fields, so an error clears while the user types, not at click time. *Changed after code review:* `change` was dropped too, because a native change event fires on blur and would bring the bug back on Description.
  - **Side effect:** an empty name is no longer flagged when you tab out of the field. It's still flagged on input and when you submit.
- **`/projects/abc`:** a non-numeric id shows the same "Project not found" card without calling the API, so it no longer requests `NaN`. I'm not adding `error.vue`: this app has none, and Nuxt's default error page would look out of place.
- **Not found:** during SSR, the page sets HTTP **404** (`setResponseStatus(useRequestEvent(), 404)`) and the tab title "Project not found".
- **Focus ring:** one rule in `assets/css/main.css` makes the `:focus-visible` outline solid `--ui-border-inverted` (neutral-900 in light mode, white in dark) on buttons and links. Nuxt UI's default is a faint tint. *Changed while building:* the primary green is only about 2.3:1 on white, below the 3:1 minimum. Width stays as Nuxt UI sets it. I'll check the contrast is at least 3:1 in the browser.
- **Search in the URL:** `?search=` and `?page=` stay in sync with the list through `router.replace`, so reloading or going Back keeps them. The query string is only used for filtering; nothing about the organization goes in it.
- **No new dependencies.**

## Backend (`api/`)
None.

## Frontend (`web/app/`)
1. **`layouts/default.vue`**
   - `UHeader` (title "SaaS Starter").
   - `#left`: `OrgSwitcher` from `lg` up.
   - Default slot: `UNavigationMenu` with Projects and Members. They're active when the route starts with `/projects` or `/members`, which gives `aria-current="page"`.
   - `#right`: user `UDropdownMenu`. Below `sm` the button shows only an icon, with `aria-label="Account menu"`.
   - `#body` (mobile menu): `OrgSwitcher` plus a vertical `UNavigationMenu`.
   - The links show only when `auth.user.current_organization` is set, as today.
2. **`components/OrgSwitcher.vue`:** cap the button width and truncate long organization names (`max-w-56 truncate`), so a long name can't widen the header.
3. **`components/ProjectForm.vue`**
   - `:validate-on="['input']"` and `:validate-on-input-delay="0"` on `UForm`, plus `eager-validation` on Name and Description.
   - On mount, focus the Name input through `UInput`'s exposed `inputRef`, which replaces `autofocus` (it only worked in the modal).
4. **`pages/projects/[id].vue`**
   - When the inline edit form opens, focus goes to Name (via step 3). After Save or Cancel, focus returns to the Edit button (template ref + `nextTick`).
   - Non-numeric id: skip the fetch and show the not-found card.
   - When the result is a 404: set HTTP 404 on SSR, and the title "Project not found".
   - In the delete modal, disable Cancel while a delete is in progress (and `:dismissible="!deleting"`).
5. **`pages/projects/index.vue`**
   - Search input `aria-label="Search projects"`.
   - The row link wraps only the project name; the description sits under it, outside the link.
   - **Below `sm`:**
     - hide the "Created by" and "Updated" columns (column `meta.class`, which Nuxt UI 4's `UTable` supports);
     - show "by X · date" in small text under the name instead.
   - `search` and `page` stay in sync with `route.query`: read on load, `router.replace` on change, so Back isn't flooded with entries.
6. **`assets/css/main.css`:** the focus-visible outline rule.

## Tests
There's no web test runner, so the checks happen in a real browser (below). API tests don't change; run them anyway as part of `verify`.

## Verification
- `/verify web` (lint, typecheck, build), then `/verify api` to confirm nothing else changed.
- `/e2e-smoke` with two e2e accounts. Account A is a member of a second organization, so that organization switching can be checked as well.
- `ui-reviewer` on the same flow as last time, plus:
  - no sideways scrolling at 390px; the hamburger menu has the org switcher and the links;
  - a quick Save after a duplicate-name error submits (5 out of 5 tries);
  - focus moves into the edit form and back to Edit;
  - focus ring contrast is at least 3:1;
  - `/projects/abc` and another organization's project return 404 with the right title and make no `NaN` request;
  - reload and Back keep the search and page;
  - organization switching (not covered last time).
- `code-reviewer` on the diff. Security, tenant isolation and database reviewers don't apply: the change is frontend presentation only, with no new data flow. I'll still run `security-auditor` briefly, because the query string is now read into the page.

## Commits after approval
`fix(web): improve projects pages and header on mobile and keyboard`, then this plan → Done.

## Outcome
- **Built as planned.** The changes found during review are in Decisions. Also built:
  - **Header:** the mobile menu has the title "Navigation" and closes after switching organization.
  - **List page:**
    - switching organization clears the search;
    - a `?page=` past the end loads the last page (inside the fetcher, so server and browser agree) and corrects the URL;
    - "New project" is now the modal's trigger, so focus returns to it.
  - **ProjectForm:** a `focusOnMount` prop for the inline edit form only. Focusing on mount inside the modal had broken the modal's focus return.
  - **Focus outlines:** header nav links (Nuxt UI draws their ring on `::before`) and text inputs now use a solid outline.
- **Reviews**
  - `code-reviewer` found 2 real bugs, both fixed:
    - the lost click could still happen on Description, because `change` fires on blur;
    - a late search update could rewrite the next page's URL, so the path is now pinned and the timer cleared on unmount.
  - `security-auditor` found nothing.
  - `ui-reviewer` ran 3 times. Run 1 passed 9 of 10 and found new issues; run 2 failed 2 of 8 (modal focus return, and a `?page=3` full load). I fixed and re-checked those two myself in Playwright:
    - `?page=3` shows the projects with the URL corrected and no extra request;
    - Escape and Cancel return focus to "New project";
    - Edit focuses Name, and Cancel returns focus to Edit;
    - no console errors.
- **Checks:**
  - `verify`: web passes (lint, typecheck, build), api passes (129 tests).
  - The e2e data was cleaned up.
- **Tooling fixes made along the way:**
  - `servers.sh start` no longer hangs when piped. Its wrapper subshells now get /dev/null for stdio.
  - `ui-reviewer` now runs on Sonnet in quick mode (only the listed checks, about 25 actions, screenshots of failures only, "full sweep" on request). It always writes to an explicit `.playwright-mcp/` path, because some Playwright tools put bare filenames in the repo root; two stray files were deleted.

## Follow-ups (not in this plan)
- **Switching organization from a project page:** `refreshNuxtData()` re-fetches that project in the new organization, so "Project not found" flashes for about 20ms with a console 404 before the page redirects to `/projects`. Files: `stores/organization.ts`, `pages/projects/[id].vue`.
- **Members page hydration mismatch:** `data-placeholder` on the role `USelect`. File: `pages/members.vue`.
- **Org switcher menu:** the keyboard highlight on menu items is faint (the Nuxt UI default, about 1.05:1).
- **Mobile menu dialog:** its `aria-describedby` points to an empty description.
- **API `search` parameter:** add a maximum length (`nullable|string|max:100`), suggested by `security-auditor` as hardening.
