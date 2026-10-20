# 04 — Organization members and invitations

**Status:** Done
**Commits:** `48a08ee` (api), `5dd3892` (web)

## Goal
Owners and admins can invite people by email, change roles and remove members; anyone can leave. All actions apply to the **current organization** (no organization id in URLs), with permissions enforced on the server.

## Permissions

| Action | Owner | Admin | Member |
|---|---|---|---|
| See members | ✓ | ✓ | ✓ |
| See / revoke pending invitations | ✓ | ✓ | – |
| Invite as member or admin | ✓ | ✓ | – |
| Invite as owner | ✓ | – | – |
| Change roles | anyone, any role | member ↔ admin only (not owners) | – |
| Remove someone | anyone | members only | – |
| Leave | ✓ | ✓ | ✓ |

An organization always keeps at least one owner: demoting, removing or leaving as the last owner → 422.

## Backend (`api/`)
1. **Migration** `organization_invitations`: organization_id, email (lowercased), role, `token_hash` (sha256, unique), invited_by (users, null on delete), expires_at (7 days), accepted_at, timestamps.
2. **`OrganizationInvitation` model** using `BelongsToOrganization` (first real tenant-owned model). Token lookups for joining bypass the scope explicitly, in one place.
3. **Policies** (`MembershipPolicy`, `OrganizationInvitationPolicy`) implementing the table above from `CurrentOrganization::role()`.
4. **Actions**: `InviteMember` (rejects existing members; re-inviting the same email replaces the pending invite with a new token), `RevokeInvitation`, `AcceptInvitation` (row lock; email must match; adds membership, marks accepted, switches to the org), `ChangeMemberRole`, `RemoveMember` (also used for leaving; if the removed user's current org was this one, move them to another of their orgs or none).
5. **Mail**: `OrganizationInvitationMail`, queued (retries + backoff). Link: `FRONTEND_URL/join/{token}`. Plain token only ever appears in the email. Add `app.frontend_url` config.
6. **Endpoints** (all `auth:sanctum`):

   | Endpoint | Notes |
   |---|---|
   | `GET /api/members` | Members of the current org with roles |
   | `PATCH /api/members/{user}` `{role}` | 404 if `{user}` isn't in the current org |
   | `DELETE /api/members/{user}` | Remove, or leave when `{user}` is me |
   | `GET /api/invitations` | Pending invitations of the current org |
   | `POST /api/invitations` `{email, role}` | Throttled 20/min per user |
   | `DELETE /api/invitations/{id}` | Revoke |
   | `GET /api/join/{token}` | Org name, inviter, role, invited email |
   | `POST /api/join/{token}` | Accept. 403 wrong email; 404 unknown/revoked/expired/used |

7. **Tests (Pest)**: permission matrix for every action; last-owner rules; leave; removed user's current org reset; invitation emails queued (`Mail::fake`) with a working link; token stored hashed; join success / wrong email / expired / used / already member / unauthenticated. **Cross-org**: org A's admin can't list, change, remove, revoke or see org B's members and invitations (404).

## Frontend (`web/`)
8. **Redirect after login**: `auth` middleware sends `?redirect=/join/...`; login/register return there. Only same-site paths (`/…`, not `//…`) are accepted, to prevent open redirects.
9. **`/members` page** (link in the header): members table with role select / Remove / Leave (shown per permissions; server decides), invite form (email + role), pending invitations with Revoke. Confirmations use a Nuxt UI modal. Data reloads when switching organizations.
10. **`/join/[token]` page**: "Ada invited you to join Acme as admin" → Accept → switches to that org. Clear messages for wrong email (with "log out and use the invited account") and invalid/expired links.
11. **`useOrganizationPermissions()`** composable mirroring the table, for showing/hiding controls only.

## Out of scope
Deleting or renaming organizations, a dedicated ownership-transfer flow (role change covers it), resend button (re-invite covers it), email verification, notifications.

## Verification
- `php artisan test`, Pint, `npm run lint`, `npm run typecheck`, `npm run build`.
- curl run on spare ports: owner invites → link from the mail log → invitee registers and joins → admin limits → member can't invite → last owner can't leave → cross-org attempts 404.
- Local email goes to `api/storage/logs/laravel.log` (`MAIL_MAILER=log`); sending needs `php artisan queue:work`.
- Commits after approval: `feat(api): add organization members and invitations`, `feat(web): add members page and invitation flow`, then this plan → Done.

## Outcome
- 101 Pest tests (56 new). Mutation checks: policies allowing everything fails 11 tests, no last-owner guard fails 2, no join email check fails 1.
- End-to-end on spare ports: invite → queued email in the log → logged-out link redirects to login and back → join as admin → reuse 404 → admin can't invite owners or remove the owner → last owner can't leave → other org gets 404 → `?redirect=//evil.example` falls back to `/`.
- Changed from plan:
  - No `RevokeInvitation` action: revoking is one scoped `delete()` in the controller.
  - Added `EnsureCurrentOrganization` middleware (`organization` alias): 403 "No active organization." for member/invitation routes.
  - The invitation mail takes plain values, not the model: a queued job has no current organization, so re-loading a `BelongsToOrganization` model there would find nothing.
  - `Tests\TestCase` resets scoped services before each test request, like a real request.
- Not done: in-browser click-through (Claude in Chrome not connected).
