# 22 — Account basics

**Status:** Done
**Commits:** `7791925` (api), `a0aa401` (web)

## Goal
Add the account features every SaaS needs and this starter is missing: forgot/reset password, email verification, an account settings page (name, email, password, delete account) and a list of signed-in devices (Sanctum tokens) with revoke.

Out of scope: 2FA, social login, transferring ownership as its own action (an owner can already make another member Owner on the Members page), deleting an organization on its own (only as part of deleting an account, below).

## Decisions
- **Soft verification gate** (user's choice). Unverified users can log in and use their organization, and see a banner with "resend". Inviting members, accepting invitations, billing and AI need a verified email (`verified` middleware alias → `App\Http\Middleware\EnsureEmailIsVerified`, 403 with `code: email_unverified`; the client's `errorMessage` points at the banner). Existing users are marked verified by a data migration so nobody gets locked out on deploy.
- **Links point at Nuxt, not Laravel.** Reset and verify emails link to `FRONTEND_URL/reset-password?token=…&email=…` and `FRONTEND_URL/verify-email?id=…&hash=…&expires=…&signature=…`. Nuxt posts the values to the API, which checks them. Our own queued notifications (`App\Notifications\VerifyEmail`, `ResetPassword`) build the links; the verify signature is relative to the API (`signed:relative`). The verify call needs the user to be logged in as that user.
- **Built-in brokers, no new dependency.** Laravel's password broker (`password_reset_tokens` table, already there, 60 min expiry) and `MustVerifyEmail` with signed URLs. No Fortify: it's built around sessions and views, and the parts we need are small.
- **No account enumeration.** Forgot password always answers 200 with the same message. Throttled: `throttle:6,1,password-reset` per IP (own counter, so failed logins don't use it up), and the broker's own per-email throttle (60 s).
- **Changing your email** needs your current password and signs out your other devices (security review). It sets `email_verified_at = null` and sends a new verification link to the new address. The old address gets a "your email was changed" notice (new address masked). A pending invitation is matched on the current email, and accepting needs a verified email, so an unverified email can't be used to accept someone else's invitation.
- **Changing your password** needs your current password and revokes every other token (other devices are signed out); the current one stays. A password reset revokes all tokens.
- **Deleting your account** (user's choice: block until resolved). Needs your password. Refused (422, listing them) if you're the last owner of any organization that has other members or an active subscription. Organizations where you're the only member are deleted with their data (existing `cascadeOnDelete` FKs). Your other memberships go through `RemoveMember` so audit entries are written. Then the user, their tokens and any reset token are deleted. The organization row is locked first (and `AcceptInvitation` now locks it before the invitation), so a concurrent join can't be wiped out by the cascade; memberships are locked owners first, like `EnsuresAnOwnerRemains`. Audit rows keep `actor_id = null` (already `nullOnDelete`).
- **Devices.** The token list shows name, IP, last used and "this device". New migration adds `ip_address` and `user_agent` to `personal_access_tokens`, set on login/register/reset. The name is built on the server from the User-Agent ("Chrome on macOS") with a small parser in `App\Support\DeviceName`, no package. You can revoke any of your own tokens; revoking the current one logs you out.
- **Audit.** These are account events, not organization events, so nothing goes in the organization audit log (it's per organization and would leak one organization's members' account activity to others). Removing memberships on account deletion still writes `member.removed` in each organization.

## Backend (`api/`)
- Migrations (new):
  - `add_device_columns_to_personal_access_tokens_table`: `ip_address` (string 45, nullable), `user_agent` (string 512, nullable).
  - `mark_existing_users_verified`: `update users set email_verified_at = now() where email_verified_at is null`.
- `User` implements `MustVerifyEmail`. The verification and reset notifications are queued (`ShouldQueue`, tries/backoff like `OrganizationInvitationMail`).
- `AppServiceProvider`: `ResetPassword::createUrlUsing` and `VerifyEmail::createUrlUsing` point at `config('app.frontend_url')`. The verify URL keeps Laravel's signature, which is checked by the API route.
- Actions (`app/Actions/Auth` and a new `app/Actions/Account`):
  - `IssueToken` (shared by login/register/reset: name from `DeviceName`, IP, user agent). `LoginUser` and `RegisterUser` use it. Register sends the verification email.
  - `SendPasswordResetLink`, `ResetUserPassword` (broker, revokes all tokens, marks the email verified, returns a new token so the user is logged in).
  - `VerifyUserEmail` (checks id = current user and the hash of the current email; the route checks signature and expiry). Resend is in the controller.
  - `UpdateProfile` (name), `ChangeEmail`, `ChangePassword`, `DeleteAccount`, `RevokeDevice`.
- Notifications: `VerifyEmail`, `ResetPassword` (queued, Nuxt links), `EmailChanged` (sent to the old address).
- Form Requests: `ForgotPasswordRequest`, `ResetPasswordRequest`, `UpdateProfileRequest`, `ChangeEmailRequest` (`current_password`, unique email), `ChangePasswordRequest` (`current_password`, `Password::defaults()`, confirmed), `DeleteAccountRequest` (`current_password`).
- Resources: `UserResource` adds `email_verified` (bool). New `DeviceResource` (id, name, ip_address, last_used_at, created_at, is_current).
- Controllers: `PasswordResetController`, `EmailVerificationController`, `AccountController`, `DeviceController`. All thin.
- Routes:
  - Guest, throttled: `POST /api/forgot-password`, `POST /api/reset-password`.
  - `auth:sanctum` (no `organization` middleware): `POST /api/email/verify/{id}/{hash}` (`signed:relative`), `POST /api/email/verification-notification`, `PATCH /api/account`, `PUT /api/account/email`, `PUT /api/account/password`, `DELETE /api/account`, `GET /api/account/devices`, `DELETE /api/account/devices/{token}` (only the user's own tokens; someone else's gives 404).
  - Add `verified` to: `POST /invitations`, `POST /join/{token}`, billing checkout/credits/portal, and the AI routes (summary, writing help, chat `store`). `bootstrap/app.php` aliases `verified` to our middleware, which answers JSON 403 `{ code: 'email_unverified' }`.
- `.env.example`: no new keys. A comment by `MAIL_MAILER` that reset and verify emails go there (`log` locally).

## Frontend (`web/`)
- Pages (auth layout, `guest` middleware): `forgot-password.vue`, `reset-password.vue` (reads `token`/`email` from the query, logs in on success). A "Forgot password?" link on `login.vue`.
- `verify-email.vue` (`auth` middleware, so you log in first and come back via `redirect`): posts the query values, then refetches `/me` and goes home with a toast. Shows an error with "resend" if the link expired or is for another account.
- `account.vue` (default layout), in sections (`UPageCard`):
  - Profile: name.
  - Email: new email + current password. Says when it isn't verified, with resend.
  - Password: current, new, confirm.
  - Devices: `UTable` with name, IP, last used, a "This device" badge and Revoke (confirm modal; revoking this device logs out).
  - Danger zone: delete account (modal with password). Shows the organizations that block it, from the 422.
- Verification banner: `VerifyEmailBanner` (`UBanner`) in `layouts/default.vue` when `auth.user.email_verified` is false, with "Resend email" (`useEmailVerification`). `errorMessage` turns a 403 `email_unverified` into a message pointing at the banner.
- `useAfterLogin` (moved out of `login.vue`): reset-password also sends people in several organizations to `/select-organization`.
- `UserMenu.vue`: an "Account settings" item (`/account`).
- Types: `User.email_verified`, `Device`.
- `README.md`: features list, the API and pages table, and a note that reset/verify emails go to the log locally (`storage/logs/laravel.log`).

## Tests
- Password reset: the link is sent for a known email and not for an unknown one, but both answer the same 200; wrong/expired token fails; success changes the password, revokes old tokens and returns a working new token; throttled.
- Verification: register sends it; a valid link verifies; a link for another user, a bad hash, a bad signature or an expired one fails; resend is throttled; already verified is a no-op.
- `verified` gate: an unverified user gets 403 `email_unverified` on invite, join, billing checkout and the AI routes, and can still list projects.
- Account: update name; change email needs the password, resets verification, sends a link to the new address and a notice to the old one, rejects an email that's taken; change password needs the current one and revokes other tokens but not the current one.
- Delete account: wrong password fails; blocked when last owner of an organization with other members, and when last owner of a sole-member organization with an active subscription (422 lists them); otherwise sole-member organizations and their data are gone, other memberships are removed with `member.removed` audits, other owners' organizations are untouched, and the tokens no longer work.
- Devices: lists only your tokens with `is_current`; revoking another device works; revoking someone else's token is 404; IP and user agent are stored on login.
- Tenant isolation: deleting an account in org A never touches org B data where the user isn't the last owner; the device endpoints can't reach another user's tokens.

## README screenshots
- New `docs/screenshots/account.png`: the account settings page (profile, email, password, devices with two devices), under "Team and admin". Captured as in plan 17 (light, 1280×800, `e2e-*` accounts cleaned up).
- No retakes: the banner only shows for unverified users, and the demo accounts are verified.

## Verification
`verify` skill (Pint, Pest, lint, typecheck, build). `e2e-smoke`: register → banner → open the verify link from the log → banner gone; forgot → reset → log in; change password signs out a second device; delete account. Reviewers in parallel: code, security, tenant isolation, database (migrations), UI (new pages).

## Follow-ups
From the reviews, not done here:
- A daily cap per user on email changes and verification resends (today 6 a minute), so the app can't be used to mail a third-party address repeatedly.
- Expire and prune Sanctum tokens (`sanctum.expiration` + scheduled `sanctum:prune-expired`); the devices list shows the latest 100.
- `Referrer-Policy: same-origin` on the Nuxt pages, so the reset token in `/reset-password`'s query can't leak if a third-party resource is ever added.

## Commits after approval
1. `feat(api): password reset and email verification`
2. `feat(api): account settings, devices and account deletion`
3. `feat(web): password reset, email verification and account settings`
4. `docs: add account settings screenshot`
5. This plan → Done.
