# SaaS Starter

**A working starting point for a team-based SaaS app**: a Laravel API (`api/`) and a Nuxt web app (`web/`) with organizations, roles and permissions, an audit log, AI features powered by Claude, Stripe billing, two-factor authentication and a platform admin area already built and tested.

Instead of starting from an empty project, you start from an app that already handles the parts every SaaS needs and that are easy to get wrong: keeping each customer's data separate, deciding who may do what, keeping AI costs under control, taking payments, securing accounts, and supporting customers without seeing their data. You then build your own product on top. **Projects** are the example feature to copy or replace.

**Contents:** [What it does](#what-it-does) · [Key ideas](#key-ideas) · [Roles](#roles-and-permissions) · [How it works](#how-it-works) · [Stack](#stack) · [Setup](#setup) · [Run](#run) · [Tests](#tests-and-checks) · [API and pages](#api-and-pages) · [Screenshots](#screenshots) · [Project layout](#project-layout) · [Working with Claude Code](#working-with-claude-code)

## What it does

A quick tour, as a user would see it:

1. **Sign up and get a workspace.** Registering creates your first **organization** (a workspace for your team or company), with you as its Owner. You can create more and switch between them from the sidebar. If you belong to more than one, you choose which to work in when you log in.
2. **Bring your team.** Invite people by email and give each a role. Use the built-in Owner, Admin and Member roles, or build your own from a list of permissions.
3. **Work on projects.** Create, search, edit and delete projects. Everyone in the organization sees the same projects; other organizations never do.
4. **Get help from AI** (Claude):
   - **Summaries:** each project gets an AI summary with key points. It's a draft until a teammate approves it, and it's flagged when the project changes later.
   - **Writing help:** "Improve with AI" rewrites, expands or shortens a description as you watch it being written. You accept or discard it.
   - **Ask AI:** a private chat about a project, which only you see.
5. **Keep AI costs in check.** Every AI call is recorded with its tokens and cost. Each organization has a monthly AI budget, and AI stops when it's used up.
6. **Pay for more.** Organizations start on **Free**. **Pro** (a monthly Stripe subscription) raises the AI budget, and one-time **AI credits** add to this month's budget on any plan. Card details, invoices and cancelling are handled on Stripe's own pages.
7. **Manage your account.** Verify your email, reset a forgotten password, change your name, email or password, turn on two-factor authentication with an authenticator app, see where you're logged in and sign out other devices, or delete your account.
8. **See what happened.** The **audit log** records who did what and when (members, roles, projects, AI reviews, plan changes and purchases), with before and after values.
9. **Run the platform.** People who run the app (platform admins, made from the command line, two-factor required) get an admin area for support:
   - **Look up** any user or organization: their organizations and roles, plan, AI budget, counts and members, never project content.
   - **Fix the usual cases:** reset a user's two-factor after a lost phone, sign them out everywhere, suspend or unsuspend them, or make a member Owner of an organization whose owners are gone.
   - **View as user:** see the app as they do, read-only. Nothing can be changed, project content stays hidden, and the user gets an email.
   - **Accountable:** every lookup and action (with the admin's reason) goes into a platform log that organizations don't see; an organization's own audit log shows changes made there as by "Platform admin".

## Key ideas

| Term | What it means |
|---|---|
| **Organization** | A workspace that owns its data (projects, roles, audit log, AI usage, billing). A person can belong to several. |
| **Current organization** | The one you're working in now, picked with the switcher in the sidebar. It's stored on the server, never sent by the browser, so a request can't reach another organization's data. |
| **Member** | A person in an organization, with exactly one role there. |
| **Role and permissions** | A role is a named set of permissions (for example "Edit any project" or "Manage billing"). The app checks permissions, never role names. |
| **Owner** | The built-in role with every permission. It can't be edited or deleted, and an organization always keeps at least one owner. |
| **Draft and approve** | AI output is saved as a draft that a person approves. AI never changes or deletes anything on its own. |
| **AI budget** | Dollars of AI an organization can spend per month (UTC). It comes from the plan; owners can set a lower limit, or 0 to turn AI off. |
| **AI credits** | A one-time purchase that adds to the AI budget for the rest of the month it's bought in. |
| **Plan** | Free or Pro, set only by Stripe through a signed webhook. |
| **Two-factor** | A 6-digit code from an authenticator app asked for after the password, with one-time recovery codes for a lost phone. |
| **Platform admin** | Someone who runs the app, not a member role. Granted only from the command line, needs two-factor, and sees accounts and organizations but never their project content. |
| **View as user** | A platform admin's read-only, time-limited session as a user, to see what they see. The user is told. |

## Roles and permissions

Every new organization starts with three roles. Admin and Member are ordinary roles that owners can change; you can add more (for example "Contributor" or "Viewer").

| Can… | Owner | Admin | Member |
|---|:---:|:---:|:---:|
| View and create projects, edit their own | ✓ | ✓ | ✓ |
| Use AI features | ✓ | ✓ | ✓ |
| Edit and delete any project | ✓ | ✓ | |
| Invite people, change roles, remove members (only roles they cover) | ✓ | ✓ | |
| View the audit log and AI usage | ✓ | ✓ | |
| Create, edit and delete roles | ✓ | | |
| Change the monthly AI budget | ✓ | | |
| Manage the plan, payment details and invoices | ✓ | | |

Nobody can hand out more than they have: a non-owner only grants, assigns or removes roles whose permissions they hold themselves.

## How it works

```
 Browser ── Nuxt app (web/) ──── Bearer token ────▶ Laravel API (api/) ──▶ PostgreSQL
                                                        │    │
                                     Claude API ◀───────┘    └──▶ Stripe (Checkout, Customer Portal)
                                     (AI calls only here)            │
                                                                     └── signed webhook ──▶ Laravel API
                                     Queue worker: emails, AI summaries
                                     Scheduler: prunes old audit log entries
```

- **The web app is only a client.** Every rule is enforced in the API: who you are (Sanctum token), which organization you act in, and what your role allows (policies).
- **Organizations stay separate on the server.** Organization-owned records are filtered to the current organization automatically, so another organization's record simply returns 404. Each feature has tests proving organization A can't read or change organization B's data.
- **AI runs only in Laravel**, behind one interface: the API key never reaches the browser, every call is recorded and checked against the budget, and tests use a fake instead of real calls. Interactive answers stream to the browser (server-sent events); slow work such as summaries runs in a queued job.
- **Billing goes through Stripe's hosted pages.** The API returns a Checkout or Customer Portal link; what was paid comes back only through Stripe's signed webhook, which updates the plan or adds credits.
- **Everything notable is audited** in the same database transaction as the change.
- **Logging in has one path.** Every token comes from the same place, which asks for the two-factor code when it's on and refuses suspended accounts.
- **Platform admin is separate from organizations.** Its routes answer 404 to everyone else, its cross-organization queries live in one folder (`app/Actions/Admin`), and it has its own log.

## Stack

| | |
|---|---|
| API | Laravel 13, PHP 8.4, PostgreSQL, Sanctum (Bearer tokens), Pest |
| Web | Nuxt 4, Nuxt UI 4, Pinia, TypeScript |

**Requirements:**
- **PHP 8.4+** (Pest 5 needs it), with the `pdo_pgsql` extension, and Composer.
- **Node 22.22.3+.** The version is in `web/.nvmrc`; run `nvm use` in `web/`.
- **PostgreSQL** running locally.

## Setup

### 1. Databases
Create two databases: one for the app, and one for the tests (the tests wipe theirs). Commands that wipe a database (`migrate:fresh`, `db:wipe`, ...) refuse to run unless its name ends in `_test`, so the app database can't be lost by accident.
```bash
createdb -h 127.0.0.1 -U postgres saas_starter
createdb -h 127.0.0.1 -U postgres saas_starter_test
```

### 2. API
```bash
cd api
composer install
cp .env.example .env        # then set DB_* to match your Postgres
php artisan key:generate
php artisan migrate
```
Key settings in `api/.env`:

| Key | Default | What it's for |
|---|---|---|
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `saas_starter`, `postgres`, empty | The app database |
| `FRONTEND_URL` | `http://localhost:3000` | The only origin CORS allows, and the base of invitation links |
| `QUEUE_CONNECTION` | `database` | Invitation emails and AI summaries are queued |
| `MAIL_MAILER` | `log` | Locally, emails (with their invitation links) are written to `storage/logs/laravel.log` |
| `ANTHROPIC_API_KEY` | empty | Claude API key, used only by the API. Without it, summaries are marked failed |
| `AI_PROVIDER` | `anthropic` | `fake` returns canned summaries with no network calls (no key needed) |
| `AI_MODEL` | `claude-haiku-5-5` | Model for all AI features |
| `AI_RATE_LIMIT_PER_MINUTE` | `5` | AI requests per organization per minute |
| `AI_MONTHLY_BUDGET_USD` | `5` | Monthly AI budget per organization on Free |
| `AUDIT_RETENTION_DAYS` | `365` | Days to keep audit log entries (organization and platform); `0` keeps them forever |
| `ADMIN_TWO_FACTOR_TTL_MINUTES` | `720` | Platform admin pages ask for a fresh two-factor code once the last one on that device is older than this |
| `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES` | `15` | Platform admin actions (suspend, reset two-factor, ...) ask for a fresh code once the last one on that device is older than this |
| `ADMIN_IMPERSONATION_MINUTES` | `30` | How long a platform admin's read-only "view as user" session lasts (not renewed) |
| `BILLING_PROVIDER` | `stripe` | `fake` makes no Stripe calls: checkout goes straight back to the billing page and changes nothing |
| `STRIPE_KEY`, `STRIPE_SECRET` | empty | Stripe API keys (test mode locally), used only by the API |
| `STRIPE_WEBHOOK_SECRET` | empty | Signing secret of the webhook. Without it every webhook is refused, so subscriptions never sync |
| `STRIPE_PRICE_PRO` | empty | Price id of the recurring Pro price. Without it, upgrading is refused |
| `BILLING_PRO_AI_BUDGET_USD` | `50` | Monthly AI budget on Pro (Free gets `AI_MONTHLY_BUDGET_USD`) |
| `STRIPE_PRICE_CREDITS_10` | empty | Price id of the one-time "$10 of AI credits" price. Without it, buying credits is refused |

The rest (AI timeouts, displayed prices, currency, mail sender, ...) is explained by comments in `api/.env.example`.

### Stripe (optional, for billing)
Billing uses [Laravel Cashier](https://laravel.com/docs/billing): the **organization** is the Stripe customer, payments happen on Stripe Checkout, and card changes, invoices and cancellation in the Stripe Customer Portal. Subscriptions and credits reach the app only through the webhook.

Without Stripe, set `BILLING_PROVIDER=fake`: every organization stays on Free (with `STRIPE_PRICE_*` set to any placeholder, Upgrade and Buy go straight back to the billing page and change nothing).

**Local setup** (Stripe test mode, nothing is charged):

1. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli) and log in:
   ```bash
   stripe login
   ```
2. Get the webhook signing secret for `STRIPE_WEBHOOK_SECRET`:
   ```bash
   stripe listen --print-secret
   ```
3. Create the two prices and copy each `price_...` id:
   ```bash
   # STRIPE_PRICE_PRO: $19 a month
   stripe prices create --unit-amount=1900 --currency=usd -d "recurring[interval]=month" -d "product_data[name]=Pro"
   # STRIPE_PRICE_CREDITS_10: $10 one time
   stripe prices create --unit-amount=1000 --currency=usd -d "product_data[name]=AI credits"
   ```
4. Turn on the Customer Portal in the Dashboard (Settings > Billing > Customer portal): allow updating payment methods, viewing invoices and canceling at the end of the period; leave plan switching off.
5. Copy the test API keys from the Dashboard (Developers > API keys) and fill in `api/.env`:
   ```dotenv
   BILLING_PROVIDER=stripe
   STRIPE_KEY=pk_test_...
   STRIPE_SECRET=sk_test_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   STRIPE_PRICE_PRO=price_...
   STRIPE_PRICE_CREDITS_10=price_...
   ```
6. Restart the API, then forward webhooks while you test:
   ```bash
   stripe listen --forward-to localhost:8000/api/stripe/webhook \
     --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,customer.updated,customer.deleted,payment_method.automatically_updated,invoice.payment_action_required,invoice.payment_succeeded,checkout.session.completed,checkout.session.async_payment_succeeded
   ```
7. Open http://localhost:3000/billing as an organization owner and pay with the test card `4242 4242 4242 4242` (any future date, any CVC).

**Production:** use live keys and live prices; add a webhook endpoint for `https://<api>/api/stripe/webhook` (Dashboard > Developers > Webhooks) with the same events as above and use its signing secret (`php artisan cashier:webhook` registers Cashier's default events only, without the two `checkout.session.*` ones that grant credits). Set up the Customer Portal the same way, and under Settings > Billing > Subscriptions and emails > Manage failed payments, set "If all retries for a payment fail" to cancel the subscription (Pro stays active while Stripe retries).

### 3. Web
```bash
cd web
nvm use
npm ci
cp .env.example .env        # NUXT_PUBLIC_API_BASE=http://localhost:8000
```

## Run

You need three terminals (four with Stripe):
```bash
cd api && PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload   # API on http://localhost:8000
cd api && php artisan queue:work     # sends queued emails and generates AI summaries (restart it after pulling code changes)
cd web && npm run dev                # app on http://localhost:3000
stripe listen --forward-to localhost:8000/api/stripe/webhook --events ...   # only for billing; full command in Setup > Stripe
```
The API needs several workers: a streamed AI answer (writing help, chat) keeps one busy until it finishes. `--no-reload` is required for workers, so restart it after changing `.env`.
In production also run the scheduler (`php artisan schedule:work`, or a cron entry for `schedule:run`); it prunes old audit log entries daily.
Open http://localhost:3000 and register; your first organization is created for you.
To make yourself a platform admin, register, turn on two-factor in Account settings, then run `cd api && php artisan admin:grant you@example.com` (`admin:revoke` undoes it). The admin area appears in the sidebar.
Locally, emails (verification, password reset, invitations) aren't sent: with `MAIL_MAILER=log` the queue worker writes them, links included, to `api/storage/logs/laravel.log`.

## Tests and checks
Run everything with one command, from the repo root (it finds PHP 8.4 and the right Node version for you):
```bash
.claude/skills/verify/verify.sh          # or: verify.sh api | verify.sh web
```
Or run each step yourself:
```bash
cd api && php artisan test           # Pest, against saas_starter_test
cd api && vendor/bin/pint --test app routes tests database config bootstrap/app.php bootstrap/providers.php
cd web && npm run lint && npm run typecheck && npm run build
```
About 550 Pest tests cover the API. Every tenant-owned feature has a test proving organization A can't read or write organization B's data. Tests never call Claude or Stripe: they use fakes (`FakeAiClient`, `FakeBillingGateway`).

## API and pages

Every feature, with its API endpoints and web page. This is the detailed reference behind [What it does](#what-it-does).

| Feature | API | Web |
|---|---|---|
| Register, log in, log out, current user | `POST /api/register`, `/login`, `/logout`, `GET /api/me` | `/register`, `/login`, `/select-organization` (after login, for people in more than one organization) |
| Email verification: a link is emailed on sign-up and after an email change. Until it's followed, a banner asks you to verify, and inviting people, accepting invitations, billing and AI are refused (403 `email_unverified`); everything else works. Accounts that existed before this feature count as verified | `POST /api/email/verify/{id}/{hash}` (signed), `POST /api/email/verification-notification` | Banner on every page, `/verify-email` (the emailed link) |
| Password reset: a link by email (same answer whether or not the account exists), valid once for 60 minutes. Resetting signs out every device and logs you in | `POST /api/forgot-password`, `POST /api/reset-password` | "Forgot password?" on `/login`, `/forgot-password`, `/reset-password` |
| Two-factor authentication (TOTP): turn it on by scanning a QR code with an authenticator app (Google Authenticator, 1Password, Authy) and entering a code; you get 8 one-time recovery codes. Then logging in, and finishing a password reset, ask for a code after the password (5 minutes, 5 tries; each code works once). Turning it on signs out your other devices; turning it on or off, and using a recovery code, emails you. Turning it off or making new recovery codes needs your password | `POST /api/account/two-factor`, `POST /api/account/two-factor/confirm`, `POST /api/account/two-factor/recovery-codes`, `DELETE /api/account/two-factor`; `POST /api/two-factor-challenge` (after login/reset) | Two-factor card on `/account`; code step on `/login` and `/reset-password` |
| Account settings: change your name; change your email or password (needs your current password, signs out your other devices; a new email needs verifying and the old address is told); devices you're logged in on (browser, IP, last active) with sign out; delete your account (needs your password; refused while you're the last owner of an organization with other members or a paid subscription; organizations where you're the only member are deleted with their data, and you leave the rest) | `PATCH /api/account`, `PUT /api/account/email`, `PUT /api/account/password`, `DELETE /api/account`, `GET /api/account/devices`, `DELETE /api/account/devices/{id}` | `/account` (account menu > Account settings) |
| Organizations: list, create, switch | `GET/POST /api/organizations`, `POST /api/organizations/{id}/switch` | Org switcher in the sidebar |
| Members: list, change role, remove (at least one owner always remains) | `GET/PATCH/DELETE /api/members` | `/members` |
| Roles: per-organization roles with permissions; create, edit, delete (not the built-in Owner, nor a role in use). People can only grant what they have | `GET/POST/PATCH/DELETE /api/roles`, `GET /api/permissions` | `/roles` |
| Invitations by email: invite, revoke, accept | `GET/POST/DELETE /api/invitations`, `GET/POST /api/join/{token}` | `/members`, `/join/{token}` |
| Projects: list with search and pagination (view, edit and delete from each row), create, edit, delete | `/api/projects` (REST) | `/projects`, `/projects/{id}` |
| Audit log: member, invitation, role, project and AI summary changes with who, when, IP and before/after values. Needs the "View the audit log" permission (Owner and Admin by default), which shows names and changes of everything audited (project names and changes only to those who can view projects); IP addresses are shown to owners only. Behind a load balancer, configure trusted proxies so the real client IP is recorded; filter by action, person and date; pruned after `AUDIT_RETENTION_DAYS` | `GET /api/audit-logs`, `GET /api/audit-log/actions` | `/audit-log` |
| AI project summaries: generated on create (or on request) as a draft, then approved (optionally after editing the text and key points) or rejected. A summary is flagged when the project's name or description changed since it was written (nothing regenerates on its own). Rate limited per organization; every AI call is recorded in `ai_usage` with tokens and cost. To summarize existing projects that never had one: `php artisan ai:summarize-projects` (`--organization=ID` to repeat, `--limit=50` in total, `--dry-run`). It only summarizes projects whose creator is still a member allowed to use AI, and skips an organization whose budget is already spent; summaries queued past the budget fail when the worker runs them | `GET/POST /api/projects/{id}/summary`, `POST /api/projects/{id}/summaries/{summaryId}/approve`, `/reject` | AI summary card on `/projects/{id}` |
| AI writing help: "Improve with AI" under a project's description improves, expands or shortens it. The answer streams in as it's written (server-sent events) and can be stopped, accepted into the form, retried or discarded; nothing is saved until the project is. Needs "Use AI features" plus create (new project) or edit (existing one); counts against the budget and rate limit | `POST /api/projects/writing-help`, `POST /api/projects/{id}/writing-help` (`text/event-stream`) | Project create and edit forms |
| Project chat: "Ask AI" on a project opens a chat about that project (its name, description and approved summary). Answers stream in and can be stopped; the conversation is saved per user and only they see it; they can clear it, and it's deleted when they leave the organization. The AI can't change anything. Needs "Use AI features" plus view projects; counts against the budget and rate limit | `GET`, `POST` (`text/event-stream`), `DELETE /api/projects/{id}/chat` | Project page |
| AI usage and budget: calls, tokens and cost by feature, person and day for a month ("View AI usage and cost", Admin by default). A monthly budget per organization (its plan's: `AI_MONTHLY_BUDGET_USD`, default $5, on Free, `BILLING_PRO_AI_BUDGET_USD` on Pro, plus AI credits bought that month; owners can lower theirs, 0 turns AI off) blocks AI requests once used up. Using AI needs the "Use AI features" permission (Admin and Member by default) | `GET /api/ai-usage?month=YYYY-MM`, `PUT /api/ai-budget` | `/ai-usage` |
| Billing: Free and Pro plans per organization, Pro raising the monthly AI budget. Upgrading opens Stripe Checkout; the Customer Portal changes the card, shows invoices and cancels (Pro stays until the paid period ends, and while Stripe retries a failed renewal). Invoices (plan and credits) are listed in the app, read live from Stripe (drafts left out, 20 at a time), with "View"/"Pay" and PDF links to Stripe's hosted pages. Plans come from `config/billing.php`; only the webhook changes a subscription, and plan changes are audited. AI credits: a one-time $10 pack (Stripe Checkout) adds to the AI budget for the rest of that UTC month, on any plan; granted once per paid checkout, and audited. Needs "Manage the plan, payment details and invoices" (Owner only by default) | `GET /api/billing`, `POST /api/billing/checkout`, `POST /api/billing/credits/checkout`, `POST /api/billing/portal`, `GET /api/billing/invoices`, `POST /api/stripe/webhook` (Stripe-signed) | `/billing`, `/billing/invoices` |
| Platform admin lookups: search users (name, email) and organizations (name), 25 a page; a user's organizations, roles, devices and last activity; an organization's plan and subscription, AI budget this month, counts (members, projects, pending invitations) and members. Admins are made with `php artisan admin:grant {email}` (they need two-factor on first) / `admin:revoke`, never through the API; turning two-factor off ends admin until granted again. Admin routes answer 404 to everyone else, and need two-factor on (403 `two_factor_required`) plus a code on this device within `ADMIN_TWO_FACTOR_TTL_MINUTES` (403 `two_factor_reauth`; logging in with a code from the app counts, a recovery code doesn't). Opening a user or organization is recorded in the platform log (who, when, IP), which organizations never see; searches aren't recorded | `GET /api/admin/users`, `GET /api/admin/users/{id}`, `GET /api/admin/organizations`, `GET /api/admin/organizations/{id}`, `GET /api/admin/audit-log`; `POST /api/account/two-factor/reconfirm` (a fresh code) | "Platform admin" in the sidebar: `/admin/users`, `/admin/organizations`, `/admin/audit-log` |
| Platform admin actions, each with a reason (10–500 characters, in the platform log only) and a code on this device within `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES`: reset a user's two-factor (signs them out everywhere and emails them), sign them out everywhere, suspend them (signed out; login, two-factor, password reset and old tokens refused with "This account is suspended") or unsuspend them, and make an existing member Owner (emailed; the organization's audit log shows "Platform admin" without the name or IP). Never on platform admins, yourself included: no admin can be made Owner this way | `POST /api/admin/users/{id}/two-factor/reset`, `POST /api/admin/users/{id}/sign-out`, `POST /api/admin/users/{id}/suspend`, `POST /api/admin/users/{id}/unsuspend`, `POST /api/admin/organizations/{id}/owners` | The Actions card on `/admin/users/{id}`; "Make Owner" in the members table on `/admin/organizations/{id}` |
| View as a user, read-only (a reason and a fresh code, as for actions; not for platform admins, yourself, suspended users or users in no organization): the app opens as them with a banner and Stop, for `ADMIN_IMPERSONATION_MINUTES`. Every change is refused (403 `impersonation_read_only`) except switching organization, which moves only the session; project descriptions, summaries, chats and audit details stay hidden, and so do their devices. The user is emailed (without the reason), each organization viewed gets a "Platform admin viewed the app as a member" audit entry, and the platform log records the start (with the reason) and the end. The session never shows in their devices and ends if the admin loses admin or two-factor | `POST /api/admin/users/{id}/impersonate`; `POST /api/logout` ends it | "View as user" in the Actions card on `/admin/users/{id}`; the banner's "Stop viewing" |

Organization routes act on the user's **current** organization (`users.current_organization_id`). Tenant-owned models use the `BelongsToOrganization` trait, so another organization's records return 404.

## Screenshots

Demo data with real Claude answers (Haiku), light mode.

### Platform admin

**Users**: everyone with an account, searchable by name or email.

![Platform admin user search with unverified, suspended, two-factor and admin badges](docs/screenshots/admin-users.png)

**Acting on a user**: view the app as them, reset their two-factor, sign them out everywhere, or suspend them, each with a reason for the platform log.

![View as user dialog on a user's admin page, with a reason filled in](docs/screenshots/admin-user-actions.png)

**Viewing as a user**: the app as they see it, read-only, with a banner to stop; project content stays hidden.

![A project page viewed as a user, with the read-only banner and its content hidden](docs/screenshots/admin-view-as.png)

**An organization** as an admin sees it: plan, AI budget, counts and members (with Make Owner), no project content.

![Platform admin page of an organization on the Pro plan with its AI budget, counts and members](docs/screenshots/admin-organization.png)

### Projects and AI

**Projects** in the current organization, with who created them.

![Projects list in the Acme Studio organization](docs/screenshots/projects.png)

**AI summary**, approved by a teammate before it shows as the project's summary.

![Project page with its approved AI summary and key points](docs/screenshots/project-summary.png)

**Writing help** streams a better description into the form; accept, retry or discard it.

![Edit project form with an AI suggestion ready to accept](docs/screenshots/writing-help.png)

**Ask AI**: a private chat about the project, streamed as it's written.

![Ask AI panel with two questions and answers about the project](docs/screenshots/project-chat.png)

### Team, account and billing

**Choose an organization** after logging in, for people in more than one.

![Organization picker after login, listing three organizations with the last used one marked](docs/screenshots/select-organization.png)

**Account settings**: profile, email, password, two-factor authentication, and the devices you're logged in on.

![Account settings with two-factor authentication on and three signed-in devices](docs/screenshots/account.png)

**Two-factor setup**: scan the QR code with an authenticator app, or enter the key, then type the code it shows.

![Two-factor setup dialog with a QR code, the key and a 6-digit code input](docs/screenshots/two-factor-setup.png)

**Members** and pending invitations, with a role per person.

![Members page with two members and two pending invitations](docs/screenshots/members.png)

**Roles** built from permissions; Owner is built in.

![Roles page with Owner, Admin, Contributor, Member and Viewer](docs/screenshots/roles.png)

**Permissions** a role grants, picked from a grouped list. Policies check these, never role names.

![Edit role dialog with the Contributor role's permissions ticked](docs/screenshots/role-permissions.png)

**Audit log** of who did what, with filters.

![Audit log with project and AI summary entries](docs/screenshots/audit-log.png)

**AI usage** against the monthly budget, by feature and person.

![AI usage page with budget, totals and usage by feature](docs/screenshots/ai-usage.png)

**Billing**: Free and Pro plans (Stripe Checkout and Customer Portal) and one-time AI credits.

![Billing page on the Pro plan with a card on file and a $10 AI credit pack](docs/screenshots/billing.png)

**Invoices** for the plan and AI credits, read live from Stripe, with links to Stripe's invoice page and PDF.

![Invoices page with a Pro subscription invoice and two AI credit invoices, all paid](docs/screenshots/invoices.png)

## Project layout
```
api/                     Laravel API
  app/Actions/           Business logic, one class per action (controllers stay thin)
  app/Actions/Admin/     The only place that reads or changes data across organizations
  app/Ai/                AI client interface, Claude client, budget, prompts
  app/Billing/           Plans, credit packs and the Stripe gateway (with a fake for tests)
  app/Http/              Controllers, Form Requests (validation), Resources (JSON), middleware
  app/Models/            Eloquent models; organization-owned ones use BelongsToOrganization
  app/Policies/          Who may do what, by permission
  app/Support/           Current organization, TOTP, recovery codes, impersonation
  config/ai.php, billing.php, admin.php   AI model, pricing and budget; plans and credit packs; admin timeouts
  tests/Feature/         Pest tests, including organization A vs B isolation tests
web/app/                 Nuxt app
  pages/                 One file per page (projects, members, roles, audit log, AI usage, billing, account, admin/...)
  components/            Org switcher, project form, AI summary card, chat, two-factor, admin actions, ...
  composables/useApi.ts  The only way to call the API (JSON and streamed answers)
  stores/                Pinia: current user and current organization
docs/plans/              One plan per task, with status (Proposed → WIP → Done) and commit hashes
docs/screenshots/        The images in this README
CLAUDE.md                Conventions and rules for working on this repo (also used by Claude Code)
.claude/                 Claude Code skills, agents and hooks (see below)
```

**Adding your own feature:** follow how Projects is built (model with `BelongsToOrganization`, policy, Form Requests, Action, Resource, page, and an isolation test). The `/tenant-feature` skill in `.claude/` walks through it step by step.

## Working with Claude Code

The repo was built with [Claude Code](https://claude.com/claude-code) and ships the setup it used in `.claude/`. [CLAUDE.md](CLAUDE.md) holds the rules it follows.

| | |
|---|---|
| **Skills** | `/plan-task` writes a plan in `docs/plans/` and waits for approval · `/tenant-feature` walks through adding an organization-owned feature · `/verify` runs Pint, Pest, lint, typecheck and build · `/e2e-smoke` starts real servers on :8011/:3011 (with emails readable from the log) · `/ship-plan` commits, pushes and marks the plan Done |
| **Review agents** (read-only) | `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer`, `database-reviewer`, `ui-reviewer` (in a real browser) |
| **Other agents** | `flow-tester` runs a plan's user flows end to end, emails included; `laravel-test-writer` writes missing Pest tests and can only edit `api/tests/` |
| **Hooks** | Block commands that wipe or drop database data, keep `laravel-test-writer` inside `api/tests/`, and let `flow-tester` only check the e2e servers and clean up its own `e2e-ft-*` accounts |
| **MCP** | Playwright (browser) and the Nuxt and Nuxt UI docs (`.mcp.json`) |

A typical change: `/plan-task`, approve, build with tests, `/verify`, run the reviewers that fit, then `/ship-plan`.

## Contributing
Read [CLAUDE.md](CLAUDE.md) for conventions: thin controllers, Actions, Form Requests, organization isolation, and docs updated in the same commit as the code they describe. Then see [docs/plans/](docs/plans/README.md) for how the project was built, task by task, including known follow-ups.
