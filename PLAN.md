# State Wars API: Laravel port plan

The Node project in `../state-wars-api` is the specification. This file maps every Node file to its Laravel class and explains how each game rule is kept.

**Status:** approved. Decisions taken: D1 Sanctum tokens replace `sessions`; D2 a, b, c keep Node's behaviour.

## 0. Stack and how it runs here

**Revised after approval:** no Docker for local work (owner's request). Everything runs natively on the Windows dev machine, against the PostgreSQL 16 and Redis services already installed there.

| Piece | Local (Windows, no Docker) | Production (Linux) | Why the difference |
|---|---|---|---|
| Framework | Laravel 12 on PHP 8.2 (XAMPP) | Same code; PHP 8.3+ recommended | Laravel 13 needs PHP 8.3; upgrade once the host has it |
| Server | Octane on **RoadRunner** (native Windows binary) | Octane on RoadRunner or FrankenPHP | Swoole has no Windows build. The code is Octane-safe either way |
| DB | PostgreSQL 16.4 service, databases `statewars` and `statewars_test` | Managed PostgreSQL 16 | |
| Redis | Redis 5.0.14 Windows service, client **predis** | Redis 7, client phpredis (picked automatically when the extension is loaded) | No phpredis build in XAMPP; predis is pure PHP |
| Jobs | `php artisan queue:work redis` and `php artisan schedule:work` | Horizon and `schedule:work` | Horizon needs `pcntl`/`posix`, which PHP on Windows doesn't have. `composer.json` declares them as platform packages so the lock file installs on both |
| Auth | Sanctum personal access tokens (SHA-256) behind a cached guard | Same | See D1 |
| Tests | Pest. Unit tests use in-memory fakes. Feature tests use `statewars_test` plus Redis DB 15 | Same in CI | |

On Windows, use `127.0.0.1` rather than `localhost` in `DATABASE_URL`/`REDIS_URL`: `localhost` tries IPv6 first and costs about 0.5s per connection. The Node black-box scripts run from Node 22 against `localhost:8080`. k6 needs a local install (`winget install k6`), which still needs the owner's OK.

A `Dockerfile` and `docker-compose.yml` will still be provided for production-like deployments (phase 6), but nothing here depends on them.

## 1. Folder layout

```
app/
  Core/
    AppException.php          status, code, message, details (Errors::* factories as static constructors)
    Errors.php                badRequest, unauthorized, forbidden, notFound, conflict, tooEarly, gone
    Clock/Clock.php           interface: nowMs(): int
    Clock/CarbonClock.php     Carbon::now()->getTimestampMs(), so travelTo()/setTestNow() control it
    Random.php                mulberry32 prng, permutation, randomSeed (exact port, see R3)
    WatTime.php               watDate, watMidnight, addDays, watWeekday, battleTimes, dayNumber, DAY_MS
    JsMath.php                round() with JS Math.round semantics (half toward +inf), toFixed, ordinal hash
    Cache/Cache.php           interface: getOrSet(key, ttlSec, Closure, ?localTtlMs), del(key)
    Cache/TwoLevelCache.php   per-worker LRU (5,000 keys) + Redis + single-flight lock
    Cache/MemoryCache.php     for unit tests
    Config/GameConfig.php     readonly value object built from env once (QUESTION_TIME_LIMIT_SEC, …)
    Config/EnvValidator.php   port of config/env.ts schema; refuses DEV_ALWAYS_OPEN in production
    Http/Middleware/          RequestId, JsonBody (empty body = no body, bad JSON = 400), BodyLimit (16 KB),
                              SecurityHeaders (helmet equivalent), ApiRateLimit
    Http/ErrorRenderer.php    every exception → {"error":{code,message,details?}}
    Http/HealthController.php /health, /ready
  Shared/Contracts/           readonly DTOs mirroring src/shared/contracts.ts
  Modules/<Name>/
    Http/Controllers/  Http/Requests/  Http/Resources/
    Services/  Repositories/Contracts/  Repositories/  Stores/Contracts/  Stores/
    Support/   (pure rules: Scoring, BattleView, Ranks)
    Jobs/  Console/
    routes.php
    <Name>ServiceProvider.php   binds interfaces → Pg/Redis classes, loads routes.php
database/migrations, database/seeders/DatabaseSeeder.php
tests/Unit, tests/Feature/<Module>, tests/Support/Memory, tests/fixtures/shuffle-vectors.json
```

Services take repository/store **interfaces**, other services, `Clock`, `Cache` and `GameConfig` in their constructor. They never touch `DB`, `Redis`, `Cache` facades, `config()` or `now()`. Module service providers are the only place that names `Pg*`/`Redis*` classes (the Laravel equivalent of `container.ts`).

## 2. Node file → Laravel class

| Node | Laravel |
|---|---|
| `config/env.ts` | `Core/Config/EnvValidator` (run in `AppServiceProvider::boot`), `config/statewars.php`, `GameConfig` |
| `container.ts` | Module `*ServiceProvider`s (singletons: services, repos, stores, cache, clock) |
| `core/cache.ts` | `Core/Cache/{Cache, TwoLevelCache, MemoryCache}` |
| `core/clock.ts` | `Core/Clock/{Clock, CarbonClock}`; tests use `Carbon::setTestNow` / `travelTo` |
| `core/errors.ts` | `Core/{AppException, Errors}` |
| `core/random.ts` | `Core/Random` |
| `core/time.ts` | `Core/WatTime` |
| `core/validate.ts` | Base `Core/Http/ApiFormRequest` (failedValidation → 400 bad_request with `[{field,message}]`) |
| `db/migrations/001_init.ts` | `database/migrations/2026_01_01_000001_init_schema.php` (one raw-SQL migration, same DDL) + Sanctum tokens migration |
| `db/migrate.ts` | `php artisan migrate` |
| `db/seed.ts` | `DatabaseSeeder` (refuses when `app()->isProduction()`) |
| `http/app.ts` | `bootstrap/app.php` (middleware, exception rendering, 404) + `Core/Http/*` |
| `http/types.ts` | `$request->user()` from the `statewars` guard (`AuthUser {id, role}`) |
| `infra/db.ts` | `config/database.php` pgsql: `DATABASE_URL`, `statement_timeout=5000`, connect timeout 5s |
| `infra/db.types.ts` | Row DTOs in each module's `Repositories/Rows/` (e.g. `EntryRow`, `BattleRow`) |
| `infra/redis.ts` | `config/database.php` redis: `REDIS_URL`, phpredis, retry 3 |
| `server.ts` | Octane FrankenPHP (`octane:frankenphp --port=$PORT`), graceful stop on SIGTERM |
| `worker.ts` | `routes/console.php` schedule + `PrepareDayJob`, `SweepJob` on Horizon |
| `shared/contracts.ts` | `Shared/Contracts/*` DTOs + each module's `Http/Resources` |
| **auth** | |
| `auth.routes.ts` | `Auth/Http/Controllers/{GuestController, LogoutController}`, `Auth/Http/Requests/GuestRequest`, `Auth/Http/Middleware/{Authenticate, RequireAdmin}` |
| `auth.service.ts` | `Auth/Services/AuthService` |
| `sessions.repository.ts` | `Auth/Repositories/Contracts/SessionsRepository`, `Auth/Repositories/SanctumSessionsRepository` |
| **users** | |
| `users.routes.ts` | `Users/Http/Controllers/{UsernameCheckController, MeController}`, `Requests/{CheckUsernameRequest, PatchMeRequest}`, `Resources/PlayerSummaryResource` |
| `users.service.ts` | `Users/Services/UsersService` |
| `users.repository.ts` | `Users/Repositories/{Contracts/UsersRepository, PgUsersRepository}` |
| `ranks.ts` | `Users/Support/Ranks` |
| **battles** | |
| `battles.routes.ts` | `Battles/Http/Controllers/EntriesController` (start, latest, current, answer, finish, reveal), `Requests/{StartRequest, EntryIdRequest, AnswerRequest, RevealRequest}`, `Resources/{BattleStartResource, ResumeResource, AnswerResultResource, BattleResultResource, RevealResource, LatestResource}` |
| `battles.service.ts` | `Battles/Services/BattlesService` |
| `scheduler.service.ts` | `Battles/Services/SchedulerService` |
| `battle.view.ts` | `Battles/Support/BattleView` (readonly, `toArray`/`fromArray` for cache), `finalizeAt()` |
| `scoring.ts` | `Battles/Support/Scoring` |
| `battles.repository.ts` | `Battles/Repositories/{Contracts/BattlesRepository, PgBattlesRepository}` |
| `entries.repository.ts` | `Battles/Repositories/{Contracts/EntriesRepository, PgEntriesRepository}` (Query Builder) |
| `answer-stats.store.ts` | `Battles/Stores/{Contracts/AnswerStatsStore, RedisAnswerStatsStore}` |
| **leaderboard** | |
| `leaderboard.routes.ts` | `Leaderboard/Http/Controllers/TableController`, `Requests/TableRequest`, `Resources/TableRowResource` |
| `leaderboard.service.ts` | `Leaderboard/Services/LeaderboardService` |
| `live-score.store.ts` | `Leaderboard/Stores/{Contracts/LiveScoreStore, RedisLiveScoreStore}` |
| `results.repository.ts` | `Leaderboard/Repositories/{Contracts/ResultsRepository, PgResultsRepository}` |
| **today** | `Today/Http/Controllers/{TodayController, SubjectsController}`, `Today/Services/TodayService` (implements `Today/Services/BattleLocator`) |
| **derbies** | `Derbies/Http/Controllers/DerbiesController`, `Services/DerbiesService`, `Repositories/{Contracts/DerbiesRepository, PgDerbiesRepository}` |
| **challenges** | `Challenges/Http/Controllers/ChallengesController`, `Requests/{CreateChallengeRequest, ChallengeIdRequest}`, `Services/ChallengesService`, `Repositories/{Contracts/ChallengesRepository, PgChallengesRepository}` |
| **stats** | `Stats/Http/Controllers/StatsController`, `Services/StatsService` (BADGES), `Repositories/{Contracts/StatsRepository, PgStatsRepository}` |
| **questions** | `Questions/Http/Controllers/{AdminQuestionsController, AdminBattlesController}`, `Requests/{QuestionRequest, ListQuestionsRequest, QuestionIdRequest, ScheduleBattleRequest, PrepareDayRequest}`, `Services/QuestionsService`, `Repositories/{Contracts/QuestionsRepository, PgQuestionsRepository}` |
| **catalog** | `Catalog/Catalog` (STATES, SUBJECTS, LEAGUE_SUBJECTS, isStateCode, stateName, stateRegion, isSubject, subjectName, ordinal) as class constants (immutable, so Octane-safe) |
| `test/support/memory.ts` | `tests/Support/Memory/{MemUsers, MemQuestions, MemBattles, MemEntries, MemResults, MemStats, MemoryLiveScoreStore, MemoryAnswerStatsStore}` |
| `test/unit/*.test.ts` | `tests/Unit/{ScoringTest, BattlesServiceTest}.php` (the same 20 cases) |

## 3. Endpoints (unchanged)

All routes registered by the module providers. Path parameters are **not** constrained in the route (`whereUuid` would turn a bad id into a 404). They are validated in the FormRequest, so a bad uuid is a 400 like in Node.

| Method, path | Auth | Status | Rate limit |
|---|---|---|---|
| POST /v1/auth/guest | public | 201 | `RATE_AUTH_PER_HOUR` per IP (replaces global, like the Fastify route override) |
| POST /v1/auth/logout | token | 204 | global |
| POST /v1/usernames/check | public | 200 | global |
| GET, PATCH /v1/me | token | 200 | global |
| GET /v1/today, /v1/subjects | token | 200 | global |
| POST /v1/entries | token | 201 (also on resume) | global |
| GET /v1/entries/latest | token | 200 / 404 | global |
| GET /v1/entries/{id}/current | token | 200 | global |
| POST /v1/entries/{id}/answers | token | 200 | 40/min per token (replaces global) |
| POST /v1/entries/{id}/finish | token | 200 | global |
| GET /v1/entries/{id}/reveal/{n} | token | 200 / 425 | global |
| GET /v1/table | public | 200 + Cache-Control | global |
| GET /v1/derbies, /v1/stats | token | 200 | global |
| GET, POST /v1/challenges; POST /v1/challenges/{id}/accept | token | 200 / 201 / 204 | global |
| GET, POST /v1/admin/questions; POST …/{id}/approve; POST /v1/admin/battles; POST /v1/admin/days/{date}/prepare | admin | 200 / 201 | global |
| GET /health, /ready | public | 200 / 503 | none |

Response bodies are exactly what Node returns, including the fields not in `contracts.ts`:
- Table rows carry `stateCode`, `ranked` and `rank`.
- Admin question endpoints return the raw snake_case row with ISO `created_at`.
- `POST /v1/admin/battles` returns a camelCase `BattleView` with ms timestamps.
- Optional fields (`highlight`, `funStat`, `reason`, `score`, `result`, `details`) are omitted, not `null`.

## 4. How each rule is implemented

**R1 Correct answer never leaks.** `PublicQuestion` DTO has no answer field. `correctIndex` is built only in `answer()`/`replay()` for the question just answered and in `reveal()` after the lock. Unit test + feature test assert the key is absent.

**R2 Server time.**
- `answer()` reads `entries.current_sent_at`. `elapsed = clock.nowMs() - sentAtMs`.
- `Scoring::scoreAnswer`: `inTime = elapsed <= limit + ANSWER_GRACE_MS`, and points `100 + JsMath::round(50 × speed)` when correct.
- Timestamps are read as ms, truncated like JS `Date`.

**R3 Per-player order.**
- `Random::prng` ports mulberry32 with explicit 32-bit arithmetic. `imul` is done by splitting into 16-bit halves, because a 32×32 product can overflow PHP's int. `>>>` becomes `& 0xFFFFFFFF`.
- `questionOrder(seed, n)` and `optionOrder(seed, index) = permutation(4, (seed + (index+1)*7919) >>> 0)` are unchanged.
- **Golden test:** `tests/fixtures/shuffle-vectors.json` is generated by importing the real `../state-wars-api/src/core/random.ts` with `node --experimental-strip-types` for 1,000 seeds. The PHP output must match it exactly.
- Answers are stored as canonical indexes.

**R4 Idempotency.** `PgEntriesRepository::recordAnswer` (Query Builder):
```php
try {
    DB::transaction(function () use ($a) {
        $ins = DB::table('entry_answers')->insertOrIgnore([...]);          // ON CONFLICT DO NOTHING
        if ($ins === 0) throw new LostRace();
        $upd = DB::table('entries')->where('id', $a->entryId)->where('current_index', $a->index)
            ->whereNull('finished_at')
            ->update(['current_index' => $a->index + 1, 'current_sent_at' => $a->answeredAt,
                      'score' => DB::raw('score + ?'), 'points' => DB::raw('points + ?')]);  // bound values
        if ($upd === 0) throw new LostRace();                               // rolls back the insert
    });
    return true;
} catch (LostRace) { return false; }                                        // service returns replay()
```
- `entries.create` uses `INSERT … ON CONFLICT (battle_id, user_id) DO NOTHING RETURNING *`, then a select.
- `finish` is `UPDATE … SET finished_at WHERE id AND finished_at IS NULL` and returns affected rows > 0. Only that caller runs the leaderboard, streak and badge updates.
- Challenge accept is a conditional update on `to_user IS NULL`.

**R5 Resume.** `resume()` is a line-for-line port. `extendCurrent` is `current_sent_at + make_interval(secs => ?)` and `pause_used_ms + ?`, guarded by `current_index = ? AND finished_at IS NULL`.

**R6 Reveal lock.**
- `BattleView::finalizeAt` = `closesAt + n*(limit*1000 + grace) + pauseBudget + 60000`.
- Before that time, `reveal()` throws 425 `too_early`, unless `DEV_ALWAYS_OPEN` is set.
- The message format is the same: `Answers unlock at HH:MM` in WAT.

**R7 Live leaderboard.**
- `RedisLiveScoreStore::add` runs `HINCRBYFLOAT lb:{id}:sum`, `HINCRBY lb:{id}:cnt`, `HINCRBY lb:{id}:hist`, plus 3 `EXPIRE`s, inside `Redis::multi()` with the same key names and TTL.
- `LeaderboardService::recordFinish` applies `UPRISING_MULTIPLIER` for uprising states.
- `finalize()` upserts `state_results` only at close.
- `AnswerStatsStore` uses `rv:{battle}:{question}` with the same MULTI.

**R8 `GET /v1/table` caching.**
- `TwoLevelCache::getOrSet`:
  1. Read the per-worker LRU, which has a TTL of `min(ttl, 2s)` or `localTtlMs`.
  2. Then read Redis (`c:` prefix, JSON with a `{"v":…}` envelope so `null` can be cached like in Node).
  3. On a miss, take `Cache::lock("sf:{key}", 5)->block(3)`, check Redis again, load, then write Redis and local.
- That makes single-flight work across all workers and servers. Node's single-flight was per process only.
- The LRU is a bounded array on a container singleton, not a static, and it is reset in tests. Under Octane it intentionally lives for the worker's lifetime, like Node's per-process LRU.
- The controller sets `Cache-Control: public, max-age=5` for tonight and `max-age=60` for week and all-time.

**R9 Streak.** `PgUsersRepository::applyBattleResult` is one `UPDATE users SET streak = CASE …, best_streak = GREATEST(best_streak, CASE …), points = points + ?, last_played_date = GREATEST(COALESCE(last_played_date, ?::date), ?::date) WHERE id = ? RETURNING streak, best_streak, points`. The SQL is copied verbatim.

**R10 Challenges and banter.**
- `ChallengesService` depends only on the challenges and entries repositories, with no leaderboard dependency, so it cannot touch the state table.
- Banter comes only from `ResultsRepository::approvedBanter()` (`banter_lines WHERE approved`), cached 600s, with the same fallback string.
- The template is picked with the same 31-hash on `"{battleId}:{state}"`.

**R11 Two-person approval.** `QuestionsService::approve` returns 403 if the approver is the author, and 409 `not_draft` if the question isn't a draft. The DB `CHECK approver_differs` stays as a backstop.

**R12 DEV_ALWAYS_OPEN.** `EnvValidator` throws at boot when `DEV_ALWAYS_OPEN=true` and the environment is production. Production means `APP_ENV=production` or, for compatibility with the Node env file, `NODE_ENV=production`. This happens in every process (web, Horizon, scheduler, artisan), so the container fails to start.

## 5. Database

- One migration runs the same DDL as `001_init.ts` through `DB::unprepared`, with identical tables, columns, CHECKs, FKs and indexes. That includes `users_username_lower`, `battles_unique_slot` on `coalesce(subject,'')`, and the partial `entries_unfinished`. `down()` drops the same tables.
- Laravel's default `users`, `sessions`, `cache` and `jobs` migrations are deleted. Cache and queue are on Redis, and the API has no web sessions.
- `failed_jobs` stays, because it's a new table and doesn't clash.
- **Arrays: keep native Postgres arrays** (`uuid[]`, `text[]` for `question_ids`, `uprising_states` and also `questions.options`), not jsonb. This will be explained in ARCHITECTURE.md:
  - The schema stays identical to Node, so data and SQL carry over.
  - `CHECK (array_length(...))` constraints stay as they are.
  - `pickFresh` keeps `questions.id = ANY(battles.question_ids)`.
  - In PHP, reads select `to_json(col)` and decode. Writes bind a JSON string and cast: `ARRAY(SELECT json_array_elements_text(?::json))::uuid[]`. That avoids hand-escaping array literals, which matters for `options`, which can contain quotes and commas.
- The seeder ports `seed.ts` step for step, using `ON CONFLICT (lower(username)) DO UPDATE` through a raw statement. It refuses to run in production.
- New tables or columns later go in new migration files. The init migration is never edited.

## 6. Cross-cutting

- **Errors.** The exception renderer maps:
  - `AppException` → its status and code.
  - Validation → 400 `bad_request` "Some of the details sent are not valid." with `details: [{field, message}]`.
  - Throttle → 429 `rate_limited`.
  - Unknown route or wrong method → 404 `not_found` "Route not found." Fastify returns 404 for both.
  - Anything else → logged, 500 `internal`.
  - `details` is omitted when empty.
- **Validation parity with zod.**
  - `prepareForValidation` does the `trim()` and `toUpperCase()` transforms and the `.default()`s.
  - Strict types: `z.boolean()` and `z.number().int()` reject `"true"` and `"3"`, so strict rules are used (custom rules if the framework lacks `boolean:strict`/`integer:strict`).
  - `options` must be an array of exactly 4. `refine` on PATCH /me → "Send stateCode or remind."
  - Field messages will be Laravel's wording, not zod's. Codes and status codes are identical.
- **Body handling (middleware).**
  - An empty or whitespace body with `Content-Type: application/json` counts as no body.
  - Invalid JSON → 400 `bad_request` "The request body is not valid JSON." Laravel silently ignores bad JSON by default, so this needs explicit handling.
  - Bodies over 16 KB are rejected, both in middleware and in Caddy's `request_body max_size`.
- **Auth guard.**
  - The `Authenticate` middleware reads `Bearer`, enforces token length 20 to 100, and calls `AuthService::authenticate`.
  - `AuthService::authenticate` uses `cache.getOrSet("sess:{sha256(token)}", 600, …)`. A miss runs one join of `personal_access_tokens` and `users`.
  - Logout deletes the token and the cache key.
  - `RequireAdmin` → 403 `forbidden`.
- **Rate limits.**
  - `RateLimiter::for` uses the Redis cache store, so limits are shared across instances.
  - `global`: `RATE_GLOBAL_PER_MIN` per `t:{sha256(token)[0:24]}`, else per `ip:{ip}`.
  - `auth`: `RATE_AUTH_PER_HOUR` per IP. `answers`: 40/min per token.
  - Route-specific limiters replace the global one, matching Fastify. /health and /ready are exempt.
  - Trusted proxies are set to `*`, like `trustProxy`.
- **Other HTTP.**
  - `X-Request-Id` is echoed or generated and added to the log context.
  - CORS comes from `CORS_ORIGINS`.
  - Helmet-equivalent headers are added, without CSP.
  - Caddy compresses responses over 1 KB.
  - Octane `max_execution_time` is 10s, and pgsql `statement_timeout` is 5s.
- **Octane safety.**
  - No mutable statics anywhere.
  - Singletons hold no request state.
  - The guard resolves the user per request.
  - The per-worker caches (TwoLevelCache LRU, BattlesService question sets, 64 battles) are bounded and only hold immutable or TTL data.
  - `--max-requests` recycles workers.
  - Graceful shutdown: FrankenPHP drains in-flight requests on SIGTERM, with compose `stop_grace_period: 15s`.
- **Env.**
  - Same names as `.env.example`: `NODE_ENV`, `PORT`, `LOG_LEVEL`, `DATABASE_URL`, `PG_POOL_MAX`, `REDIS_URL`, `CORS_ORIGINS`, `PUBLIC_WEB_URL`, all game rules, `DEV_ALWAYS_OPEN`, `RATE_*`.
  - Laravel's own `APP_KEY` and `APP_ENV` are added.
  - `PG_POOL_MAX`: PHP has no pool, since each Octane worker holds one connection. It is used to cap the Octane worker count and documented, with PgBouncer recommended for multiple instances.

## 7. Jobs

- **`PrepareDayJob`** (queue `default`, Horizon):
  - Calls `SchedulerService::prepareDay(WatTime::watDate(clock))` and logs `{created, skipped}`.
  - Scheduled `dailyAt('00:05')->timezone('Africa/Lagos')->withoutOverlapping()->onOneServer()`.
  - `php artisan statewars:prepare-day` also runs once when the scheduler starts (like `prepare-now` in `worker.ts`), so a missed midnight is covered.
- **`SweepJob`** (every minute, `withoutOverlapping()->onOneServer()`):
  - `dueForClose()`, then `closeBattle($id)` for each. That settles unfinished entries in batches of 500 through `listUnfinished`, then finalizes and marks the battle finalized.
  - Both jobs are idempotent and use the job middleware `WithoutOverlapping`.
- **Admin `POST /v1/admin/days/{date}/prepare`** calls the same service directly.

## 8. Tests

1. **Unit, Pest, no DB.**
   - `ScoringTest`: 12 cases.
   - `BattlesServiceTest`: 8 cases. It runs on `tests/Support/Memory/*`, `MemoryCache`, the memory stores and `CarbonClock` with `Carbon::setTestNow`.
   - Plus the golden shuffle test, and a "services import no facades" architecture test with Pest `arch()`.
2. **Feature, real Postgres.** For every endpoint: happy path, validation 400, auth 401/403, and the module's error codes.
   - Battles: concurrent duplicate answers, double finish (Redis count 1), reveal 425, close job idempotency.
   - Leaderboard: Cache-Control headers.
   - Plus `/ready`, empty and invalid JSON bodies, the body limit, and the rate-limit 429 shape.
3. **Black box, unchanged scripts**, against the local Octane server on :8080 with `DEV_ALWAYS_OPEN=true MIN_PLAYERS_RANKED=1`:
   - `node ../state-wars-api/test/load/smoke.mjs` must give 28/28.
   - `node ../state-wars-api/test/load/burst.mjs 500`, with `RATE_AUTH_PER_HOUR` and the answers limit raised, must give 0 5xx.
   - Then `php artisan statewars:verify-battle` checks:
     - `SELECT count(*)` of `entry_answers` = 5,000.
     - No duplicate `(entry_id,q_index)`, which the PK already guarantees.
     - Per state, the Redis `lb:{id}:cnt` and `:sum` equal `count(*)` and `sum(score)` of finished entries.
4. **k6:** `k6 run ../state-wars-api/loadtest/battle.k6.js` (local k6 install). The results (thresholds, p95, RPS, and the machine they ran on) go in ARCHITECTURE.md as measured, even if thresholds fail. A 2,000-VU run on one Windows laptop measures this laptop, not production.

## 9. Phases (git repo initialised in this folder, one commit per phase after tests pass)

1. Setup: Laravel and Octane in Docker, Core, config/env validation, migration, seeder, error handling, middleware, health. Unit tests for Random (golden), WatTime and Ranks.
2. Auth and Users: Sanctum guard, cache, the username rules, state-change cooldown, the streak SQL.
3. Battles: the engine, scheduler, stores, and the 20 ported unit tests.
4. Leaderboard and Today.
5. Derbies, Challenges, Stats, Questions admin.
6. Jobs, rate limits, Octane tuning, the full docker-compose (postgres, redis, migrate, api, horizon, scheduler), README, ARCHITECTURE.md, CLAUDE.md.
7. Smoke, burst and k6 runs, fixing until they pass, and recording the results.

Note: `composer create-project` needs an empty folder, so the skeleton is created in a temporary folder and moved in next to this file.

## 10. Decisions to confirm before coding

**D1. Sanctum tokens replace the `sessions` table.**
- The brief asks for Sanctum and also for identical table names, so one of the two has to give.
- Plan: drop `sessions` and use Sanctum's `personal_access_tokens`. Its migration is adjusted to `uuidMorphs`, because user ids are uuids.
- Tokens are issued with `createToken('guest')`. The format becomes `id|random`, which the frontend treats as opaque.
- Requests are **not** authenticated by `auth:sanctum`, which costs one query plus a `last_used_at` write per request. They use the cached lookup above, so auth stays nearly free at 8pm as in Node.
- Alternative: keep Node's `sessions` table and token format, and use Sanctum only for hashing. That isn't really "Sanctum".

**D2. Node behaviours that look wrong. I'll match them unless you say otherwise.**
- a. **Oversized body and wrong Content-Type.** In Node, Fastify raises 413 and 415, but the error handler only special-cases 429 and 400, so both become **500 `internal`** and get logged as errors. I'd recommend 413 `payload_too_large` and 415 `unsupported_media_type`, but that changes status codes, so by default I'll keep the 500.
- b. **Duplicate username at sign-up returns 409 `username_unavailable`.** That comes from the availability check. `username_taken` only happens when two sign-ups race. Your error-code list mentions `username_taken`, so I'm treating that list as illustrative and keeping Node's codes.
- c. **`finish()` and the close job settle using the entry row read before the `finished_at` update.** With `DEV_ALWAYS_OPEN`, an answer landing between that read and the update would be missing from the Redis totals. In production it can't happen, because finish is only allowed after the last answer or after `finalizeAt`. Changing it to `UPDATE … RETURNING score, points` is safe and contract-neutral. I'll keep Node's behaviour unless you want the fix.
