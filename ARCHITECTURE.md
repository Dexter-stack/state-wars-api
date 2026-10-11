# Architecture

A Laravel port of the Node State Wars API. Same HTTP contract, same game rules, same database schema (PostgreSQL), same Redis keys. `PLAN.md` maps every Node file to its Laravel class.

## Layers

Every feature is a **module** in `app/Modules/<Name>/` with the same layers:

| Layer | Where | Does | Never does |
|---|---|---|---|
| Controller | `Http/Controllers` | Take a validated FormRequest, call one service method, return a Resource and status code | Business rules, queries |
| FormRequest | `Http/Requests` | Port of the zod schema: transforms (trim, upper-case, defaults), strict types, 400 with `[{field, message}]` | |
| Resource | `Http/Resources` | Exact camelCase JSON of `contracts.ts` | |
| Service | `Services` | Business rules: game logic, permissions, orchestration | Facades, `DB`, `Redis`, `config()`, `now()`, HTTP |
| Repository | `Repositories/Contracts` + `Pg*` | Data access behind an interface | Business rules |
| Store | `Stores/Contracts` + `Redis*` | Same idea for Redis-backed live counters | |
| Provider | `<Name>ServiceProvider` | Binds interfaces to Pg/Redis classes (the only place that names them), loads `routes.php` | |

Shared code lives in `app/Core`: `AppException`/`Errors`, `Clock` (Carbon, so `travelTo` works in tests), `Random` (mulberry32), `WatTime`, `JsMath` (JavaScript rounding and `toFixed`), the two-level `Cache`, `GameConfig`, `EnvValidator`, HTTP middleware and the error renderer. Response DTOs live in `app/Shared/Contracts` and mirror `state-wars-web/src/api/types.ts`.

Services depend on interfaces, so `tests/Support/Memory/*` can replace every repository and store, and the 20 Node unit tests run on services without a database. `tests/Unit/ArchitectureTest.php` enforces the boundaries with Pest `arch()` rules.

```
HTTP request (Octane worker)
  → RequestId, TrustProxies, CORS, security headers, JsonBody (empty body = none, bad JSON = 400, 16 KB)
  → throttle (Redis, per token else per IP) → auth.player (token → user, cached) → auth.admin
  → FormRequest (validate) → controller → service (rules)
  → repositories (PostgreSQL) / stores (Redis) / Cache
  → Resource (contract JSON) | ErrorRenderer ({"error":{code,message,details?}})
```

## Modules

| Module | Responsibility | Key endpoints |
|---|---|---|
| `Auth` | Guest sign-up, Sanctum bearer tokens (SHA-256 stored), `auth.player` / `auth.admin` | `POST /v1/auth/guest`, `POST /v1/auth/logout` |
| `Users` | Profile, username rules, state changes (once per 30 days, first correction free), streaks, ranks | `POST /v1/usernames/check`, `GET/PATCH /v1/me` |
| `Today` | Home screen, subject leagues, `BattleLocator` | `GET /v1/today`, `GET /v1/subjects` |
| `Battles` | Engine: start, answer, resume, finish, reveal; scheduler; close job | `/v1/entries…` |
| `Leaderboard` | Live table (Redis), final results (Postgres), week/all-time, banter, Uprising | `GET /v1/table` |
| `Derbies` | Tonight's derby, the week, weekly rival | `GET /v1/derbies` |
| `Challenges` | Friend challenges (never touch the state table) | `GET/POST /v1/challenges`, `POST /v1/challenges/:id/accept` |
| `Stats` | History, accuracy, badges | `GET /v1/stats` |
| `Questions` | Question bank with two-person approval; admin scheduling | `/v1/admin/…` |
| `Catalog` | 37 states, subjects (constants) | — |

## The battle engine (rules that must not break)

- **The answer never leaves early.** `PublicQuestion` has no answer field. `correctIndex` appears only in the answer to that question, and in reveal after the lock.
- **Server-owned time.** `entries.current_sent_at` is the clock. An answer counts if `elapsed ≤ limit + ANSWER_GRACE_MS`; points are `100 + round(50 × speed)`, with JavaScript rounding.
- **Per-player shuffles.** Question and option order come from the entry seed via mulberry32, ported bit for bit. `tests/fixtures/shuffle-vectors.json` holds 1,000 seeds generated from the Node source, and the PHP output must match them exactly. Answers are stored as canonical option indexes.
- **Idempotent writes.** `PgEntriesRepository::recordAnswer` is one transaction:
  1. `insertOrIgnore` into `entry_answers`, whose primary key is `(entry_id, q_index)`.
  2. `UPDATE entries … WHERE current_index = ? AND finished_at IS NULL`.
  3. If either step touches 0 rows, the transaction rolls back and the service replays the stored answer.

  `finish` is guarded by `finished_at IS NULL`, so the leaderboard, streak and badges update exactly once.
- **Connection loss.** Resume tops the current question up to `MIN_RESUME_MS` out of a `PAUSE_BUDGET_MS` budget.
- **Reveal lock.** `425 too_early` until `finalizeAt` (`BattleView::finalizeAt`).
- **Streak.** One `UPDATE users SET streak = CASE …, best_streak = GREATEST(…), … RETURNING`, copied from the Node SQL.
- **Banter** comes only from approved `banter_lines`. **Challenges** have no dependency on the leaderboard.
- **Two-person approval.** The service rule, with a DB `CHECK` as a backstop.
- **`DEV_ALWAYS_OPEN`** is refused at boot in production, in every process.

## Built for the 8pm rush

| Pressure | How it's handled |
|---|---|
| Thousands finishing at once | Live state totals are Redis hash increments in one MULTI (`lb:{battle}:sum/cnt/hist`): O(1), no row locks. Postgres gets one row per state at close. |
| Everyone polling the table | `GET /v1/table` is identical for everyone. It uses a two-level cache: 2s in the Octane worker's memory, then 5s in Redis. A Redis lock gives single-flight across all workers and servers, and `Cache-Control: public, max-age=5` lets a CDN serve it. |
| Auth on every request | Token → user is cached 2s in-process and 10 min in Redis. It's one indexed join on a miss, and Sanctum's per-request `last_used_at` write is avoided. |
| Hot reads during play | Battles are cached (Redis plus per-worker), and question sets stay in worker memory (immutable, max 64). The answer path is one entry read plus one small transaction. |
| Retries on bad networks | Every write is idempotent (see above). |
| Abuse | Redis rate limits shared by all instances: global per token, else per IP. Sign-up is per IP per hour and answers are 40/min. Plus the 16 KB body limit, a 10s Octane request timeout and a 5s Postgres `statement_timeout`. |
| Scaling out | Octane workers are stateless apart from bounded caches. Run as many instances as needed behind a load balancer. Scheduled jobs are `onOneServer` and `withoutOverlapping`. |
| Deploys | RoadRunner drains in-flight requests on SIGTERM (`destroy_timeout: 15s`), and `/ready` checks Postgres and Redis. |

**Octane safety.** There is no mutable static state. The two worker-lifetime caches (the `TwoLevelCache` LRU and the question sets in `BattlesService`) are container singletons, bounded, holding only shared immutable or TTL'd data. The signed-in player lives on the request (`AuthUser` attribute), never in a singleton.

## Data model

`database/migrations/2026_01_01_000001_init_schema.php` is the Node `001_init.ts` DDL, unchanged apart from two things:
- The `sessions` table is replaced by Sanctum's `personal_access_tokens`, using `uuidMorphs` with an FK to `users` and `ON DELETE CASCADE`.
- A `failed_jobs` table is added for the queue.

Main tables: `users`, `personal_access_tokens`, `questions`, `battles`, `entries`, `entry_answers`, `state_results`, `derbies`, `challenges`, `user_badges`, `banter_lines`, `states`.

### Why native Postgres arrays (not jsonb)

`battles.question_ids uuid[]`, `battles.uprising_states text[]` and `questions.options text[]` stay as Postgres arrays:

1. **Same schema as Node.** Data, SQL and dumps carry over, and both APIs could run against one database during a migration.
2. **Constraints stay declarative.** `CHECK (array_length(options, 1) = 4)` and `CHECK (array_length(question_ids, 1) BETWEEN 1 AND 20)` would need jsonb equivalents written by hand.
3. **The "fresh questions" query keeps `questions.id = ANY(battles.question_ids)`.** That is the natural form for it, with typed uuids.

The cost is that PDO doesn't map arrays, so `App\Core\Db\Pg` handles it in one place:
- Reads select `array_to_json(col)` and decode it.
- Writes bind a JSON string and let Postgres build the array: `ARRAY(SELECT json_array_elements_text(?::json))::uuid[]`.

Nothing is escaped by hand, which matters for `options`, whose text can contain quotes and commas.

## Local environment vs production

| | Windows dev machine (no Docker) | Production (Linux) |
|---|---|---|
| PHP | 8.2 (XAMPP) | 8.3+ |
| Server | RoadRunner (`rr.exe`) with Octane's worker, started by `serve-windows.ps1` (`octane:start` needs POSIX signals) | `octane:start --server=roadrunner` (Dockerfile) |
| Redis client | predis | phpredis (chosen automatically when the extension is loaded) |
| Queue | `queue:work` | Horizon (needs `pcntl`) |
| `ext-sockets` for RoadRunner | loaded only for this project via `PHP_INI_SCAN_DIR=php-ini` | in the image |

## Tested here

- **99 Pest tests**, broken down as:
  - The 20 Node unit tests, ported case for case, on in-memory fakes.
  - 1,000 golden shuffle vectors from the Node source.
  - Architecture rules.
  - Feature tests for every endpoint against PostgreSQL 16 and Redis. These cover concurrent duplicate answers, an exactly-once finish, the reveal lock, idempotent close jobs, the rate-limit shape, Cache-Control and the transaction rollback.
- **Black-box scripts from the Node repo**, unmodified, against this server on RoadRunner: smoke 28/28, and the 500-player burst. k6 was not run. See "Load test results".

## Load test results

Run on 2026-10-11 on the Windows dev machine: Intel i7-6600U (2 cores / 4 threads), 8 GB RAM, with PostgreSQL 16, Redis, the API and the test client all on the same machine. PHP 8.2 (XAMPP, no OPcache), predis, RoadRunner 2025.1.15 with 8 Octane workers, started with `serve-windows.ps1`. These numbers show correctness under contention, not production capacity.

| Test | Result |
|---|---|
| `test/load/smoke.mjs` | 28 of 28 checks pass |
| `test/load/burst.mjs 500` | 500 of 500 players completed; 8,065 requests in 61.4s (131 requests/s); p50 3.7s, p95 7.0s, p99 7.4s; 0 server errors; 0 failed players; no duplicate-answer mismatches |
| `statewars:verify-battle` after the burst | 5,010 answer rows, 5,010 distinct `(entry_id, q_index)`; Redis player counts and sums equal Postgres for all 8 states, and equal the totals the burst script expected |
| `loadtest/battle.k6.js` | Not run: k6 is not installed on this machine |

Notes on the burst:
- Rate limits were raised for the run (`RATE_GLOBAL_PER_MIN` and `RATE_AUTH_PER_HOUR` set to 1,000,000), because all 500 players come from one IP.
- Latency is high because 500 players queue for 8 workers on 2 cores that are shared with the database, Redis and the client. It has not been measured on production-like hardware.
- Two earlier attempts ended with `ECONNREFUSED` in the client. The first was because RoadRunner listened on IPv4 only while Node tries `localhost` as IPv6 first; the script now binds both. The second happened once just after a restart and did not repeat; its cause was not found. The server logged no errors either time.

## Not built yet

Same list as the Node API:
1. Phone OTP sign-in and account recovery.
2. Share images.
3. Web Push reminders.
4. Reports and moderation.
5. Final rank thresholds (`Users/Support/Ranks.php` holds placeholders).
6. A read replica for stats and history.
