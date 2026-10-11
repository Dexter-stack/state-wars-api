# State Wars API (Laravel): notes for Claude Code

Read `ARCHITECTURE.md` first. The Node API in `../state-wars-api` is the specification: same endpoints, fields, status codes and error codes. If a behaviour is unclear, read the Node code and match it; if it looks wrong, ask rather than change it silently.

## Structure rules
- One folder per feature in `app/Modules/<Name>/`: `Http/Controllers` (thin), `Http/Requests` (all validation), `Http/Resources` (exact camelCase output), `Services` (rules only), `Repositories/Contracts` + `Pg*` (SQL only), `Stores/Contracts` + `Redis*`, `<Name>ServiceProvider` and `routes.php`.
- Services take repository/store **interfaces**, other services, `Clock`, `Cache` and `GameConfig` in their constructor. Never facades, `DB`, `Redis`, `config()`, `now()` or HTTP inside a service. Only the module providers name `Pg*` / `Redis*` classes. `tests/Unit/ArchitectureTest.php` enforces this.
- Validate every input in a FormRequest extending `App\Core\Http\ApiFormRequest`. Port zod semantics exactly: trim and upper-case transforms, defaults, strict booleans and integers (`StrictBoolean`, `StrictInteger`). Path parameters are validated there too, never with route constraints, because a bad uuid must be a 400.
- Throw `App\Core\Errors::*` for expected failures, with a message a player can understand and the same `code` as the Node API.
- Time comes from the injected `Clock`. Use `JsMath` where Node used `Math.round` or `toFixed`: PHP rounds negative halves differently.
- Response shapes live in `app/Shared/Contracts` and must match `state-wars-web/src/api/types.ts`. Omit optional fields; don't send them as null.
- Octane: no mutable static state. Worker-lifetime caches must be bounded singletons holding shared data only; per-request data lives on the request.
- New tables or columns: add a new migration file. Never edit `2026_01_01_000001_init_schema.php` after it has run in production.

## Game rules that must not break
- The correct answer never leaves the server before the player has answered that question.
- Time is measured on the server from `current_sent_at`.
- Answer and finish stay idempotent: unique keys plus conditional updates in one transaction. Leaderboard, streak and badges update exactly once per entry.
- Per-player orders come from `App\Core\Random` (mulberry32) and must match the Node vectors in `tests/fixtures/shuffle-vectors.json`.
- Challenges never change the state table.
- Reveal stays locked until `finalizeAt`.
- Banter comes only from approved `banter_lines` templates, never from player input.
- Questions need approval by someone other than the author.
- `DEV_ALWAYS_OPEN` must never be true in production (the env validator refuses at boot).

## Before finishing any change
`php vendor/bin/pest` (feature tests need the `statewars_test` database and Redis). For battle or leaderboard changes, also run the API locally and `node ../state-wars-api/test/load/smoke.mjs`.

On Windows: use `127.0.0.1` in `DATABASE_URL`/`REDIS_URL`, and set `PHP_INI_SCAN_DIR=php-ini` before starting Octane (RoadRunner needs `ext-sockets`).

## Open work
OTP sign-in, share images, Web Push reminders, reports/moderation, final rank thresholds.
