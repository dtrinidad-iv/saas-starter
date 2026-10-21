# 24 — Two-factor authentication

**Status:** Done
**Commits:** `72fb58c` (api), `4e60b33` (web), `57a0e0e` (docs)

## Goal
Let any user protect their account with an authenticator app (TOTP, the 6-digit codes from Google Authenticator, 1Password, Authy…), with one-time recovery codes in case they lose the phone. It comes first in the platform admin series: plan 25 (admin foundation) will require it for platform admins, plan 26 adds admin actions (including "reset a user's 2FA") and plan 27 adds read-only impersonation.

Out of scope: requiring 2FA for an organization's members (could be an organization setting later), SMS or email codes, WebAuthn/passkeys, "remember this device", an admin resetting someone's 2FA (plan 26).

## Decisions
- **TOTP written in the app, no Composer package.** RFC 6238 is about 40 lines (HMAC-SHA1, 30 s steps, 6 digits, base32 secret). `App\Support\Totp` is tested against the RFC's own test vectors. This avoids `pragmarx/google2fa` and the Packagist trouble on this network. The secret comes from `random_bytes(20)`, and codes are compared with `hash_equals`.
- **One new npm dependency: `uqr`** (needs your OK). It draws the QR code for the authenticator app as an SVG, in the browser. It's from unjs (the Nuxt team), has no dependencies of its own and is about 10 kB. Without it, people would have to type the 32-character key by hand. The secret is only shown during setup, to the user who owns it.
- **Logging in becomes two steps when 2FA is on.** `POST /api/login` with a correct password returns `{ two_factor: true, challenge: "<random>" }` and no token. The challenge is a random 40-character string kept in the cache for 5 minutes (stored as its sha256, pointing at the user id), with at most 5 tries. Then `POST /api/two-factor-challenge` with `challenge` + `code` (or `recovery_code`) returns the usual `{ user, token }`. A wrong password and a wrong code give different errors, which is fine: at the second step the password is already proven.
- **A password reset doesn't get around 2FA.** Today `reset-password` logs you in straight away. With 2FA on, it still changes the password and signs out every device, but then returns a challenge instead of a token. Otherwise, access to the email inbox alone would be enough to take over the account.
- **No reusing a code.** `two_factor_last_used_step` stores the time step of the last code accepted, and a code from that step or earlier is refused. Codes from one step before or after are accepted, to allow for clocks that are slightly off.
- **Storage on `users`:**
  - `two_factor_secret`: `encrypted` cast (APP_KEY).
  - `two_factor_recovery_codes`: JSON array of sha256 hashes. The codes are random, so sha256 is enough, and checking 8 of them with bcrypt would be slow.
  - `two_factor_confirmed_at`.
  - `two_factor_last_used_step`.

  All of these are `$hidden`. 2FA only counts as on once `confirmed_at` is set, so a setup you started but didn't finish never locks you out.
- **Recovery codes.** You get 8, formatted `xxxxx-xxxxx`, shown once when you confirm setup and again only if you create a new set. Each works once. When you use one, you get an email ("a recovery code was used, N left"), and making a new set emails you too (from the security review).
- **Turning 2FA on or off.**
  - Starting setup, turning it off and getting new recovery codes all need your current password (like changing your email).
  - Confirming setup needs a valid code from the app.
  - Turning it on signs out your other devices, the same as changing your password.
  - Turning it on or off sends an email to the account ("Two-factor authentication was turned on/off"), so an attacker can't do it quietly.
- **Audit.** These are account events, so nothing goes in any organization's audit log (same reason as plan 22).
- **Throttles.**
  - `throttle:6,1,two-factor` on the challenge, as its own counter so it doesn't share one with login.
  - The 5-try limit per challenge; the 5th wrong code ends it at once.
  - **Per account** (from the security review): 10 wrong codes in 15 minutes, across challenges and IPs, pause two-factor logins for that account and email it once. The per-IP throttle alone let someone with the password and many IPs keep guessing.
- **A challenge is tied to the account state and works once** (from the reviews). It stores a fingerprint of the password hash and `two_factor_confirmed_at`, so one made before a reset or a new setup stops working. It is used up while the user row is locked, so two requests with two different valid codes can't both get a token.
  - `throttle:6,1,account` on the account endpoints.

## Backend (`api/`)
- Migration (new) `add_two_factor_columns_to_users_table`: `two_factor_secret` (text, nullable), `two_factor_recovery_codes` (json, nullable), `two_factor_confirmed_at` (timestamp, nullable), `two_factor_last_used_step` (bigInteger, nullable).
- `App\Support\Totp`: `generateSecret()`, `verify(secret, code, ?lastUsedStep): ?int` (returns the accepted step), `provisioningUri(secret, email, issuer)` (`otpauth://totp/{app}:{email}?secret=…&issuer={app}`, issuer = `config('app.name')`), and base32 encode/decode.
- `User`: casts and `$hidden` for the new columns; `hasTwoFactorEnabled()`.
- Actions (`app/Actions/Account`):
  - `StartTwoFactorSetup`: writes a new unconfirmed secret and returns `secret` + `otpauth_uri`. Refused if 2FA is already confirmed.
  - `ConfirmTwoFactor`: checks the code, sets `confirmed_at`, creates recovery codes, revokes your other tokens and sends `TwoFactorEnabled`. Returns the plain recovery codes.
  - `DisableTwoFactor`: clears all four columns and sends `TwoFactorDisabled`.
  - `RegenerateRecoveryCodes`.
- Actions (`app/Actions/Auth`):
  - `IssueTwoFactorChallenge`: puts the challenge in the cache.
  - `CompleteTwoFactorChallenge`: checks the challenge, the try count, then the code or recovery code; sets the last used step or removes the recovery code it used (with the user row locked, so two requests can't both use the same code); forgets the challenge; issues a token through `IssueToken`.
  - `LoginUser` and `ResetUserPassword` return either `{user, token}` or `{two_factor: true, challenge}`.
- Notifications (queued, like `EmailChanged`): `TwoFactorEnabled`, `TwoFactorDisabled`, `RecoveryCodeUsed`.
- Form Requests: `StartTwoFactorRequest`, `DisableTwoFactorRequest` and `RegenerateRecoveryCodesRequest` (`current_password`), `ConfirmTwoFactorRequest` (`code`: 6 digits), `TwoFactorChallengeRequest` (`challenge`, and either `code` or `recovery_code`).
- `UserResource`: add `two_factor_enabled` (bool).
- Controllers: `TwoFactorController` (account side), `TwoFactorChallengeController`. `AuthController` and `PasswordResetController` pass the challenge response through unchanged.
- Routes:
  - Guest: `POST /api/two-factor-challenge` (`throttle:6,1,two-factor`).
  - `auth:sanctum`, `throttle:6,1,account`:
    - `POST /api/account/two-factor` (start)
    - `POST /api/account/two-factor/confirm`
    - `DELETE /api/account/two-factor`
    - `POST /api/account/two-factor/recovery-codes`
- `.env.example`: no new keys.

## Frontend (`web/`)
- Types: `User.two_factor_enabled`; `LoginResponse = AuthResponse | { two_factor: true, challenge: string }`; `TwoFactorSetup` (`secret`, `otpauth_uri`).
- Auth store: `login` and `resetPassword` return `'two-factor'` when the API asks for a code and keep the challenge in memory (not in a cookie). Add a `completeTwoFactor({ code } | { recovery_code })` that calls `setSession`.
- `login.vue`: after the password step, the same card turns into "Enter the code from your authenticator app". It uses `UPinInput` (6 digits, `otp`, submits when all six are filled), has a "Use a recovery code instead" link that switches to a text input, and "Back" to start over. An expired challenge (5 minutes, or 5 wrong tries) sends you back to the password step with a message. Then `useAfterLogin` runs as it does today.
- `reset-password.vue`: the same second step after a reset when 2FA is on. To share it, the code step becomes a `TwoFactorChallengeForm.vue` component used by both pages.
- `account.vue`: a new "Two-factor authentication" `UPageCard` between Password and Devices.
  - When off: a short explanation and "Turn on".
  - Turning it on:
    1. A password modal.
    2. A setup modal showing the QR (`uqr` SVG), the key in groups of four with a copy button, and a 6-digit `UPinInput`.
    3. A recovery codes view with Copy and Download (.txt) and "I've saved them".
  - When on: an "Enabled" badge, "New recovery codes" (password, then the codes view) and "Turn off" (password modal).
- `README.md`:
  - Features list: item 7 gets "turn on two-factor authentication".
  - The API and pages table gets the new routes.
  - The 2FA steps are described for the login.

## Tests
- `Totp`: the RFC 6238 SHA1 test vectors; codes one step either side are accepted and two steps away are refused; a used step is refused; base32 round trip.
- Setup:
  - Start needs the password.
  - Confirming with a wrong code fails and leaves 2FA off.
  - Confirming with a right code turns it on, returns 8 codes, revokes other tokens (but not the current one) and sends `TwoFactorEnabled`.
  - Starting again while it's on is refused.
  - A setup that was started but not confirmed doesn't affect login.
- Login:
  - With 2FA on, login returns a challenge and no token.
  - A valid code returns a working token.
  - Reusing the same code is refused.
  - A wrong code counts toward the 5 tries, after which the challenge is gone.
  - A challenge older than 5 minutes is refused.
  - A recovery code works once, is then removed, and sends `RecoveryCodeUsed`.
  - The challenge endpoint is throttled.
  - Login without 2FA works as before.
- Reset password with 2FA on: the password is changed and the tokens are revoked, but you get a challenge and no token.
- Off and new codes: both need the password. Turning it off clears the columns and sends `TwoFactorDisabled`. New codes replace the old ones, and the old ones stop working.
- `/me` and `UserResource` never contain the secret or recovery codes.
- Account isolation: one user's challenge can't log in as another user; the account endpoints only ever touch the logged-in user. No tenant-owned models, so no org A/B test is needed beyond that.

## README screenshots
- New `docs/screenshots/two-factor-setup.png`: the setup modal on the account page with the QR code and the key, under "Team and admin". The demo account is deleted afterwards, so its secret is worthless.
- Retake `docs/screenshots/account.png`, because the page gains the 2FA card.
- Captured as in plan 17 (light, 1280×800, `e2e-*` accounts cleaned up).

## Verification
`verify` skill (Pint, Pest, lint, typecheck, build). `e2e-smoke` with `--mail`. Reviewers in parallel: code, security, tenant isolation, database (migration), UI (login, reset and account pages).

Flows (for `flow-tester`). Codes are worked out from the key shown on the setup screen with a PHP one-liner using `App\Support\Totp`, run through `php artisan tinker --execute`.
1. Sign up and verify. On Account, turn on 2FA with a wrong password → error, nothing changes.
2. Turn it on, enter a wrong code → error and still off; enter the right code → recovery codes shown, card says Enabled, the "turned on" email arrives.
3. Log out and log in with the password → asked for a code. Enter a wrong code → error. Enter the right code → logged in.
4. Log out and log in using a recovery code → logged in, and the "recovery code used" email arrives. Logging in again with the same recovery code → refused.
5. Forgot password → reset from the email link → asked for a code, and only logged in after entering it.
6. New recovery codes → an old code no longer works. Turn 2FA off → the next login doesn't ask for a code, and the "turned off" email arrives.

## Follow-ups
From the reviews, not done here:
- Ask for a current code (not only the password) to turn two-factor off or make new recovery codes, so a stolen session plus a phished password can't remove it.
- A per-email limit on `/login` (today per IP), which now also limits how often challenges can be opened.

## Commits after approval
1. `feat(api): two-factor authentication with TOTP and recovery codes`
2. `feat(web): two-factor setup and login challenge`
3. `docs: two-factor screenshots`
4. This plan → Done.
