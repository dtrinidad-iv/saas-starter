# 01 — API foundation

**Status:** Done
**Commits:** `359fd42`

## Goal
Fresh Laravel app in `api/` on PostgreSQL with Sanctum token auth, Pest tests, and CORS for the Nuxt app. No organizations yet.

## Scope
- Laravel 13 on PHP 8.4, PostgreSQL `saas_starter` (tests: `saas_starter_test`).
- Sanctum personal access tokens (Bearer), Pest 5.
- `POST /api/register`, `POST /api/login` (throttled), `POST /api/logout`, `GET /api/me`.
- Thin `AuthController`, `RegisterUser` / `LoginUser` actions, Form Requests, `UserResource`.
- CORS: `api/*` from `FRONTEND_URL` only.

## Outcome
- 20 Pest tests (success, validation, wrong password, unauthenticated, CORS).
- Fixed: unauthenticated API requests without `Accept: application/json` returned 500 (redirect to missing `login` route); now JSON 401.
- Pest 5 makes PHP 8.4 a hard requirement.
