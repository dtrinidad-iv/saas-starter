# 02 — Nuxt app with token auth

**Status:** Done
**Commits:** `b7b13be`

## Goal
Register, log in, see the current user and log out in the browser against the Laravel API.

## Scope
- Nuxt 4 (code in `web/app/`), Nuxt UI 4 (+ required `tailwindcss`), Pinia, ESLint, vue-tsc. Node 22.22.3+ (`web/.nvmrc`).
- `useApi` composable: base URL, `Accept`, Bearer token; 401 clears the session.
- `auth` store: token in a cookie (`skipHydrate`), user loaded from `/api/me` by a startup plugin.
- Pages `/login`, `/register`, `/`; layouts `default` and `auth`; `auth` / `guest` middleware.

## Outcome
- Lint, typecheck, build pass; SSR flows verified with curl (redirects, bad-token cleanup, CORS).
- Fixed: `isLoggedIn` was true for a malformed `/api/me` response; date rendering hydration mismatch.
- Not done: in-browser click-through (Claude in Chrome not connected); token cookie is not httpOnly (proxy option deferred).
