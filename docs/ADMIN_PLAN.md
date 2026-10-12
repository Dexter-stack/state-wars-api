# Admin panel backend: plan

Status: **for review. No code has been written.** Nothing here starts until the decisions in section 2 are answered.

## 1. Which codebase

The request is written for the Node API (`src/container.ts`, zod, `npm run …`, otplib). This plan builds it in **this Laravel API** instead, because player accounts, sign-in, mandatory sign-up, queued email and the `Today`/table additions exist only here; the Node API has none of them, so an admin panel built there could not manage the players this API creates.

The Node API is not touched. If you want it in Node instead, say so and this plan needs redoing.

How the Node terms map here:

| In the request | Here |
|---|---|
| `routes.ts` + zod | `routes.php`, a controller and a FormRequest per endpoint |
| Service, repository interface + `Pg` class | The same: `Services`, `Repositories/Contracts`, `Pg*` |
| Wired only in `src/container.ts` | Wired only in each module's `ServiceProvider` |
| `admin.routes.ts` in each module | `admin.routes.php` in each module, loaded by its provider |
| `app.requireAdminRole(...perms)` preHandler | Route middleware `admin:<permission>` |
| Migrations 002, 003… registered in `migrate.ts` | New timestamped files in `database/migrations` (Laravel finds them itself) |
| `src/infra/db.types.ts` | Row classes next to each repository (`*Row.php`) |
| `npm run admin:create -- --email … --name …` | `php artisan statewars:admin-create --email=… --name=…` |
| `npm run typecheck && npm test` | `php vendor/bin/pest` (includes the architecture rules) |
| argon2id | PHP's built-in `PASSWORD_ARGON2ID` (confirmed available on this machine) |
| otplib | See decision D3 |

## 2. Decisions I need from you

Each has my recommendation first. D1 to D4 block step 1.

| # | Question | Recommendation |
|---|---|---|
| D1 | Build in Laravel, not Node? | Yes (section 1). |
| D2 | Existing questions point at **player** accounts as author and approver. With admins moved to their own table, what happens to those links? | Repoint both columns to `admin_users` and clear them on existing rows. Only the 10 local sample questions are affected; there is no production data. The seeder then creates two admin users instead of two admin players. |
| D3 | The TOTP library is a new dependency. | Add `pragmarx/google2fa` (the standard PHP TOTP package). The alternative is about 60 lines of our own code, which I'd rather not own for a security feature. The API returns the `otpauth://` link; the admin panel draws the QR code. |
| D4 | An admin who loses their phone is locked out for good unless there is a reset. The request doesn't cover this. | An owner can reset another admin's 2FA (audited). For the last owner, a CLI command: `statewars:admin-reset-2fa --email=…`. |
| D5 | Logo upload needs an S3 client, a second new dependency, plus R2 credentials only you can supply. | Add `league/flysystem-aws-s3-v3`. I can build and unit-test it, but **cannot test a real upload** until you provide the bucket, key and secret. |
| D6 | "Update the smoke test" means editing `smoke.mjs`, which lives in the Node repo and signs players up as guests. | Leave the Node script alone. Add `tests/load/admin-smoke.mjs` in this repo for the admin flows. |
| D7 | Should a suspended player be able to sign in and look around, or be locked out entirely? The request only says they can't start a battle and their sessions are revoked. | Follow the request literally: they can sign in again and view, but starting a battle returns 403 `account_suspended`. Say if you want sign-in refused too. |

Smaller choices I will make unless you object:

- **TOTP secrets are stored encrypted** with the app key, not in plain text as the table list implies.
- **Admin tokens look different from player tokens** (`adm_…`) and live in their own table. A player token on an admin route, or an admin token on a player route, fails because each side only ever looks in its own table.
- **`users.role` stays as a column** (the first migration is shared with Node and may not be edited) but nothing reads it any more.
- **Limits:** admin login 10 per minute per IP; reports 10 per day per player, as specified.
- **Question search needs the `pg_trgm` Postgres extension.** It is available but not installed locally; the migration installs it. A managed Postgres must allow `CREATE EXTENSION pg_trgm` (most do).
- **Audit in the same transaction:** services may not touch the database directly here, so I add one small `Transaction` interface that services use to wrap "write + audit record" together.

## 3. Roles and permissions

One map in code (`App\Modules\AdminAuth\Support\Permissions`), checked by `can(role, permission)` and by the `admin:<permission>` middleware. Owner has every permission.

| Permission | owner | editor | reviewer | moderator | sponsor_manager |
|---|:-:|:-:|:-:|:-:|:-:|
| `dashboard.read` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `admins.manage` | ✓ | | | | |
| `audit.read` | ✓ | | | | |
| `questions.read` | ✓ | ✓ | ✓ | | |
| `questions.write` (create, edit, import) | ✓ | ✓ | | | |
| `questions.approve` (approve, reject) | ✓ | | ✓ | | |
| `questions.retire` | ✓ | ✓ | ✓ | | |
| `battles.read` (calendar, detail, live) | ✓ | ✓ | ✓ | | |
| `battles.write` (schedule, prepare, swap) | ✓ | ✓ | | | |
| `banter.read`, `derbies.read` | ✓ | ✓ | ✓ | | |
| `banter.write`, `derbies.write` | ✓ | ✓ | | | |
| `banter.approve` | ✓ | | ✓ | | |
| `players.read` | ✓ | | | ✓ | |
| `players.moderate` (suspend, rename) | ✓ | | | ✓ | |
| `reports.handle` | ✓ | | | ✓ | |
| `sponsors.manage` | ✓ | | | | ✓ |

"Not the author" rules apply to owners too: an owner cannot approve their own question or banter line.

## 4. Tables

New migrations, in this order. The first migration file is never edited.

| Step | Table or change |
|---|---|
| 1 | `admin_users` (id, email unique, name, password_hash, role, totp_secret, totp_enabled, disabled, failed_logins, locked_until, created_at, last_login_at) |
| 1 | `admin_sessions` (token_hash PK, admin_id, created_at, expires_at, last_seen_at, ip, user_agent, plus `totp_passed` so a password-only session can do nothing but finish 2FA) |
| 1 | `audit_log` (id, admin_id, action, entity_type, entity_id, before jsonb, after jsonb, ip, created_at); indexes on created_at, admin, entity |
| 2 | `questions`: add difficulty, tags text[], source, licence, reject_reason, rejected_by, updated_at, version; status gains `rejected`; author and approver repointed to `admin_users` (D2); `pg_trgm` extension and a trigram index on the normalised text |
| 2 | `entry_answers`: index on `question_id` (for usage counts and % correct) |
| 4 | `users`: add suspended_until, suspend_reason |
| 4 | `reports` (id, reporter_id, target_user_id, reason, note, status, handled_by, handled_at, created_at) |
| 5 | `banter_lines`: add status (draft, approved, retired), author_id, approved_by, created_at. The existing `approved` boolean is kept in step with status, because the game reads it |
| 6 | `sponsors` (id, name, logo_url, link_url, placements text[], starts_on, ends_on, active, created_at) |
| 7 | Indexes the dashboard queries turn out to need (`entries.started_at`, `users.created_at`) |

## 5. Endpoints

All under `/v1/admin` unless marked. Every list takes `limit` (max 100), `offset` and a sort. Every write is validated, permission-checked and audited.

### Step 1: admin auth and audit

| Method and path | Permission | Does |
|---|---|---|
| POST `/auth/login` | public, rate-limited | Email and password. Returns a session token and whether 2FA is `required`, `setup_needed` or done. Locks for 15 minutes after 5 failures |
| POST `/auth/2fa/setup` | password-only session | Returns the secret and `otpauth://` link (first login only) |
| POST `/auth/2fa/verify` | password-only session | Checks the code; turns 2FA on at first use; upgrades the session to full |
| POST `/auth/logout` | any admin session | Ends the session |
| GET `/auth/me` | any admin session | Name, email, role, permission list |
| POST `/auth/password` | full session | Change own password; ends other sessions |
| GET, POST `/admins`; PATCH `/admins/:id` | `admins.manage` | List, create, change role, disable or enable. An owner cannot demote or disable the last owner |
| POST `/admins/:id/reset-2fa` | `admins.manage` | D4 |
| GET `/audit` | `audit.read` | Filters: admin, action, entity type and id, date range |

Sessions last 12 hours from last use. The five existing admin endpoints move behind the new middleware, and the old player-role check is deleted. CORS gains `ADMIN_ORIGIN`.

CLI: `statewars:admin-create`, `statewars:admin-reset-2fa`.

### Step 2: question bank

| Method and path | Permission | Does |
|---|---|---|
| GET `/questions` | `questions.read` | Filters: status, subject, difficulty, author, text search, used or unused. Each row has usage count and, if used, % correct |
| GET `/questions/:id` | `questions.read` | One question with its usage |
| POST `/questions` | `questions.write` | Creates a draft; returns likely duplicates without blocking |
| PATCH `/questions/:id` | `questions.write` | Draft or rejected only; a rejected one returns to draft. Never a used question |
| POST `/questions/:id/approve` | `questions.approve` | Not by the author |
| POST `/questions/:id/reject` | `questions.approve` | Reason required |
| POST `/questions/:id/retire` | `questions.retire` | Approved and not in a future battle |
| DELETE `/questions/:id` | `questions.write` | Only if never used in any battle |
| POST `/questions/import?dryRun=true` | `questions.write` | CSV, max 1,000 rows. Dry run returns per-row errors and likely duplicates. Real run saves the valid rows as drafts in one transaction and returns a summary |
| GET `/questions/health` | `questions.read` | Approved and unused-in-60-days counts per subject and difficulty; days of runway for the daily battle and each league |

### Step 3: battles

| Method and path | Permission | Does |
|---|---|---|
| GET `/battles?from=&to=` | `battles.read` | Calendar: status, question count, players |
| GET `/battles/:id` | `battles.read` | Questions in canonical order with answers; after close, the state table and answer distribution per question |
| POST `/battles` (exists) | `battles.write` | Schedule with chosen or auto-picked questions |
| POST `/days/:date/prepare` (exists) | `battles.write` | Prepare a day |
| POST `/battles/:id/swap` | `battles.write` | Replace one question before `opens_at`; the replacement must be approved and unused in 60 days. Refused once the battle has opened |
| GET `/battles/live` | `battles.read` | Tonight: started, finished, per-state counts |

Battle detail shows correct answers to admins. That does not break "the answer never leaves the server early": the rule is about players, and these routes only accept admin sessions.

### Step 4: players and reports

| Method and path | Permission | Does |
|---|---|---|
| GET `/players?q=` | `players.read` | Username prefix search |
| GET `/players/:id` | `players.read` | State, rank, points, streak, recent entries, badges, reports against them. The email is shown to moderators |
| POST `/players/:id/suspend`, `/unsuspend` | `players.moderate` | Reason required, optional end date; revokes their sessions |
| POST `/players/:id/rename` | `players.moderate` | New username and a reason |
| GET `/reports` | `reports.handle` | Open reports grouped by target |
| POST `/reports/:id/action` | `reports.handle` | suspend, rename or dismiss, with a note; closes every open report on that target |
| POST `/v1/reports` (player) | signed-in player | Reason, optional note, target username; 10 a day |

### Step 5: content

| Method and path | Permission | Does |
|---|---|---|
| GET, POST `/banter`; POST `/banter/:id/approve`, `/retire` | `banter.read`, `.write`, `.approve` | Templates may use only `{state}` and `{rank}`; approval not by the author |
| GET `/derbies?month=`; POST, PATCH, DELETE `/derbies/:id` | `derbies.read`, `.write` | Only future derbies can be created, changed or deleted |
| GET `/derbies/suggest?month=` | `derbies.read` | Pairings by region, avoiding recent repeats |

### Step 6: sponsors

| Method and path | Permission | Does |
|---|---|---|
| GET, POST `/sponsors`; PATCH, DELETE `/sponsors/:id` | `sponsors.manage` | CRUD; placements from the fixed list |
| POST `/sponsors/upload-url` | `sponsors.manage` | Presigned upload link for a logo (image types only, size-capped) |
| GET `/v1/sponsors/active` (public) | none | Today's sponsor per placement: name, logo, link. Cached 60 seconds |

### Step 7: dashboard

| Method and path | Permission | Does |
|---|---|---|
| GET `/dashboard` | `dashboard.read` | Players today and yesterday, new sign-ups, finishes, next-day return rate for 7 days, players per state tonight, open reports, bank runway, next battle status. Cached 60 seconds |

## 6. Effects on what already exists

- **Game rules in `CLAUDE.md` are unchanged.** Two get extended: "questions need approval by someone other than the author" now names admin users, and banter gains the same rule.
- **Breaking for the existing admin endpoints only:** they stop accepting player tokens. Nothing in the player app uses them.
- **Player-visible changes:** a new `POST /v1/reports`, a new public `GET /v1/sponsors/active`, and 403 `account_suspended` when a suspended player starts a battle. `docs/FRONTEND_API.md` gets these; a separate `docs/ADMIN_API.md` documents the admin side for whoever builds the panel.
- **The Node smoke script keeps passing** (it needs `GUEST_SIGNUP=true`, as now). Its one admin check, "a non-admin is blocked", still holds.

## 7. Tests

Unit tests on services with in-memory repositories, as the existing engine tests do, for every rule named in the request:

- Author cannot approve their own question or banter line.
- A used question cannot be edited or deleted, only retired.
- A battle locks at `opens_at`; a swap needs an approved, 60-day-fresh replacement.
- A suspended player cannot start a battle; an expired suspension lifts itself.
- Player tokens are rejected on admin routes and admin tokens on player routes.
- CSV dry run reports row errors and saves nothing; a real import is all-or-nothing for its valid rows.
- Lockout after 5 failed logins; a password-only session can reach nothing but 2FA; the last owner cannot be removed.
- Permission map: one test walks every admin route and asserts each role gets the access the table in section 3 says.

Feature tests against PostgreSQL and Redis for each endpoint, plus `tests/load/admin-smoke.mjs` (D6).

## 8. Order of work

I stop after each step for your review, as asked.

1. Admin auth and audit
2. Questions
3. Battles
4. Players and reports
5. Content
6. Sponsors
7. Dashboard

Before step 1 there is one piece of housekeeping I recommend: **commit the current work.** About 50 files are uncommitted on `main` (player accounts, sign-up, permanent results, queued email, docs). Step 1 rewrites how admin routes authenticate, and reviewing it on top of that pile will be hard.
