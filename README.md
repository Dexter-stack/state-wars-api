# State Wars API (Laravel)

Backend for State Wars: a daily 8pm quiz where Nigerians play for their state. Laravel 12, PHP 8.2+, Octane (RoadRunner), PostgreSQL 16, Redis, Horizon.

This is a port of the Node API in `../state-wars-api` with **the same HTTP contract and game rules**: same endpoints, field names, status codes, error codes and environment variables. The existing frontend and the Node test scripts work against it unchanged.

Read `ARCHITECTURE.md` for how it's built and why, `PLAN.md` for the Node → Laravel file map.

## Run locally (Windows, no Docker)

Needs PHP 8.2+ (XAMPP's is fine), Composer, PostgreSQL 16 and Redis running as services.

```powershell
copy .env.example .env          # then set DATABASE_URL (use 127.0.0.1, not localhost, on Windows)
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed             # sample data and today's battle (refuses in production)

# RoadRunner needs ext-sockets; php-ini/ loads it for this project only (XAMPP's php.ini is untouched)
$env:PHP_INI_SCAN_DIR = "$PWD\php-ini"
php vendor/bin/rr get-binary -f 2025.1.15        # once: downloads rr.exe (about 60 MB)
.\serve-windows.ps1              # API on port 8080 with 8 workers (-Port, -Workers to change)

# in two other terminals
php artisan queue:work redis      # jobs (Horizon in production; it needs pcntl, which Windows PHP lacks)
php artisan schedule:work         # prepare-day at 00:05 WAT, sweep every minute
```

`php artisan octane:start` does not run on Windows (it needs POSIX signal constants), so `serve-windows.ps1` starts RoadRunner with the same options. On Linux use `octane:start` as in the Dockerfile.

`DEV_ALWAYS_OPEN=true` (in `.env.example`) lets you play a battle at any time and see answers straight away. The app refuses to boot with it in production.

## Commands

| Command | Does |
|---|---|
| `.\serve-windows.ps1` (Windows) · `php artisan octane:start --server=roadrunner …` (Linux) | API |
| `php artisan horizon` | Queue workers (Linux) |
| `php artisan schedule:work` | Scheduler: `prepare-day` 00:05 WAT, `sweep` every minute |
| `php artisan migrate` | Apply database migrations |
| `php artisan db:seed` | Local sample data (refuses in production) |
| `php artisan statewars:prepare-day [date]` | Create a day's battles now |
| `php artisan statewars:sweep` | Close battles whose last player has finished |
| `php artisan statewars:verify-battle [id]` | No duplicate answers; Redis totals equal Postgres totals |
| `php vendor/bin/pest` | Unit tests (no database) and feature tests (PostgreSQL `statewars_test`, Redis db 15) |
| `node ../state-wars-api/test/load/smoke.mjs` | Hits every endpoint on a running local API |
| `node ../state-wars-api/test/load/burst.mjs 500` | 500 players at once (raise `RATE_*` limits first) |
| `k6 run ../state-wars-api/loadtest/battle.k6.js` | Realistic 8pm load test against staging |

Feature tests need a `statewars_test` database on the same server as `DATABASE_URL`; they refuse to run against any other database.

## API

Identical to the Node API. All endpoints are under `/v1`, JSON in and out. Send `Authorization: Bearer <token>` (from sign-up) except where marked public. Errors look like `{ "error": { "code": "already_played", "message": "…" } }`.

```
POST  /v1/auth/guest                 public  { username, stateCode, remind } → 201 { token, player }
POST  /v1/auth/logout                        204
POST  /v1/usernames/check            public  { username } → { available, reason? }
GET   /v1/me                                 PlayerSummary
PATCH /v1/me                                 { stateCode?, remind? }
GET   /v1/today                              Today (status, countdown, my state, derby)
GET   /v1/subjects                           subject leagues with leaders
POST  /v1/entries                            { league? } → 201 first question (or resumes)
GET   /v1/entries/latest                     latest finished entry
GET   /v1/entries/:id/current                resume after reconnecting
POST  /v1/entries/:id/answers                { index, choice } → { correct, correctIndex, points, next }
POST  /v1/entries/:id/finish                 BattleResult
GET   /v1/entries/:id/reveal/:n              "How Nigeria answered" (425 too_early until everyone finishes)
GET   /v1/table?period=tonight|week|all&subject=all|english|…   public, cacheable
GET   /v1/derbies
GET   /v1/challenges · POST /v1/challenges { entryId } → 201 · POST /v1/challenges/:id/accept → 204
GET   /v1/stats
GET   /health · GET /ready
Admin: GET/POST /v1/admin/questions · POST /v1/admin/questions/:id/approve
       POST /v1/admin/battles · POST /v1/admin/days/:date/prepare
```

One visible difference: guest tokens are Sanctum tokens (`123|…`). Clients treat tokens as opaque, so nothing changes for them.

## Production checklist

- `APP_ENV=production`, `DEV_ALWAYS_OPEN=false`, real `APP_KEY`, `CORS_ORIGINS`, managed Postgres and Redis with backups.
- `php artisan migrate --force` before deploying a new API version; `config:cache` and `route:cache` on boot (the Dockerfile does both).
- At least 2 API instances behind a load balancer using `/ready`; Horizon on one or more hosts; one `schedule:work` (or more: jobs are `onOneServer`).
- Octane workers per instance ≤ `PG_POOL_MAX` (PHP has no pool: one connection per worker). Put PgBouncer in front of Postgres when running many instances.
- Put `GET /v1/table` behind a CDN.
- Load test staging with k6 before launch.
- `Dockerfile` / `docker-compose.yml` describe a Linux deployment (PHP 8.3, phpredis, pcntl). They were not built on the Windows dev machine.
