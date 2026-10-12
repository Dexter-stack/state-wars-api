# State Wars API: frontend integration guide

Everything a web client needs to build against the State Wars API. It was written from the Laravel source and checked against a running server on 2026-10-11; the example responses are real output from that run.

State Wars is a daily quiz. Every night at 20:00 West Africa Time (WAT, UTC+1) a 15-minute window opens. Each player answers 10 questions, one at a time, 60 seconds each, and their score counts toward the average of their Nigerian state. States are ranked against each other.

## 1. Connection basics

| | |
|---|---|
| Base URL (local) | `http://127.0.0.1:8080` (make it configurable, e.g. `VITE_API_URL`) |
| Prefix | All game endpoints are under `/v1`. `/health` and `/ready` are not. |
| Format | JSON in, JSON out. Field names are camelCase. Responses are bare objects or arrays, never wrapped in `{ data }`. |
| Auth | `Authorization: Bearer <token>` on every endpoint except the public ones (sign-up, sign-in, forgot and reset password, username check, table). |
| CORS | Only origins in the server's `CORS_ORIGINS` are allowed. Locally that is `http://localhost:5173` only, so run the dev server on that origin or ask for yours to be added. No cookies or credentials are used. |
| Times | Every timestamp is a number of **milliseconds since the Unix epoch**. Durations ending in `Ms` are milliseconds; `timeLimitSec` is seconds. |
| Game days | A "day" is a WAT calendar day. Format times for display in `Africa/Lagos`, not the device time zone. |

### Request body rules

These are strict, and getting them wrong produces confusing errors:

- Send `Content-Type: application/json` whenever there is a body. A non-empty body with any other content type (for example form-encoded) returns **500 `internal`**, not 400.
- An empty body is fine on POSTs that take no input (`/v1/entries`, `/finish`, `/accept`, `/logout`).
- Bodies over 16 KB return 500 `internal`.
- Types are strict. Booleans must be JSON `true`/`false` (not `"true"`, not `1`). Integers must be JSON numbers (not `"3"`). Only path and query parameters accept numeric strings.
- A malformed uuid in a path is a **400**, not a 404.

### Rate limits

| Limit | Applies to | Keyed by |
|---|---|---|
| 240 per minute | Everything except the two below | Token if sent, otherwise IP |
| 20 per hour | `POST /v1/auth/signup` (creating the account) | IP |
| 10 per minute | Sign-up code request, sign-in, forgot password and reset password, together | IP |
| 40 per minute | `POST /v1/entries/:id/answers` | Token |

Over the limit you get **429 `rate_limited`**. The server sends `Retry-After` and `X-RateLimit-*` headers, but the CORS config exposes no response headers, so browser code on another origin cannot read them. Back off on a fixed schedule instead.

The sign-up limit is easy to hit while developing (20 new accounts per hour from one machine). Reuse a stored token instead of signing up on every reload.

## 2. Errors

Every error, from any endpoint, has this shape:

```json
{ "error": { "code": "already_played", "message": "You've already played this battle. Come back tomorrow." } }
```

Validation errors add `details`:

```json
{ "error": { "code": "bad_request", "message": "Some of the details sent are not valid.",
  "details": [ { "field": "username", "message": "The username field must be present." },
               { "field": "remind", "message": "Expected boolean." } ] } }
```

`message` is written for players and is safe to show as-is. Branch on `code`, never on `message`.

| Status | `code` | Meaning | What the client should do |
|---|---|---|---|
| 400 | `bad_request` | Invalid input, or a rule broken (`details` present for field errors) | Show `message`; map `details` onto form fields |
| 401 | `unauthorized` | Missing, malformed or ended token; or the account no longer exists | Clear the stored token and go to the welcome screen |
| 401 | `invalid_credentials` | Sign-in failed (same answer for a wrong password, an unknown name, and an account with no password) | Show `message` on the sign-in form. Do not clear any stored token |
| 400 | `invalid_code` | Emailed code is wrong, expired, already used, or was killed by 5 wrong tries | Show `message`; offer "send a new code" |
| 403 | `forbidden` | Not an admin, or approving your own question | Show `message` |
| 404 | `not_found` | Unknown route, wrong HTTP method, entry that isn't yours, no finished battle yet | Depends on endpoint (see below) |
| 410 | `gone` | `POST /v1/auth/guest` was called: guest sign-up is closed | Use `POST /v1/auth/signup` |
| 409 | `username_unavailable` | Sign-up name taken, reserved or malformed | Show `message` under the username field |
| 409 | `email_taken` | Email already has an account (only ever told to someone who proved they own it) | Show `message`; at sign-up, offer "Sign in instead" |
| 409 | `already_secured` | Account already has an email and password (every account made through sign-up does) | Nothing to do |
| 409 | `not_secured` | Changing the password of an old guest account that has none | Not reachable for accounts made through sign-up |
| 409 | `wrong_password` | Current password is wrong when changing it | Show `message` under the field |
| 409 | `state_change_cooldown` | State already changed in the last 30 days | Show `message` (it contains the next allowed date) |
| 409 | `already_played` | This battle is already finished for this player | Go to the result screen |
| 409 | `already_answered` | All questions answered but not finished | Call finish with `today.myEntry.entryId` |
| 409 | `out_of_order` | `index` is ahead of the current question | Call `GET /current` and resync |
| 409 | `finished` | Answering a finished entry | Go to the result screen |
| 409 | `not_done` | Finishing before answering everything | Call `GET /current` and resync |
| 409 | `not_finished` | Creating a challenge from, or reading the result of, an unfinished entry | Finish first |
| 409 | `challenge_taken` | Someone else accepted the challenge | Show `message` |
| 425 | `too_early` | Battle not open, or reveal still locked | Show `message` (it contains the time) |
| 429 | `rate_limited` | Too many requests, too many sign-in tries for one name (10 per 15 minutes), or a second code requested within a minute | Show `message`; wait, then retry |
| 500 | `internal` | Server fault, oversized body or wrong content type | Generic retry message |

Admin-only codes: `not_draft`, `not_enough_questions`.

## 3. TypeScript types

Copy these into `src/api/types.ts`. They match what the server sends.

```ts
export type Rank = "Olodo" | "Jambite" | "Average Joe" | "Efiko" | "Professor";
export type SubjectId = "english" | "maths" | "science" | "gist" | "sports" | "music";
export type BattleStatus = "upcoming" | "live" | "closed";
export type TablePeriod = "tonight" | "week" | "all";

export interface ApiErrorBody {
  error: { code: string; message: string; details?: { field: string; message: string }[] };
}

export interface PlayerSummary {
  username: string;
  stateCode: string;       // 2 letters, see section 8
  rank: Rank;              // from lifetime points
  pointsToNext: number;    // 0 at the top rank
  streak: number;          // 0 unless they played today or yesterday (WAT)
  bestStreak: number;
  battles: number;         // finished battles
  average: number;         // mean correct answers per battle, 1 decimal
}

export interface GuestSession { token: string; player: PlayerSummary } // also returned by sign-in and password reset
export interface AccountStatus { secured: boolean; email?: string }   // secured is true for every account made through sign-up
export interface SignupBody {        // POST /v1/auth/signup
  username: string; stateCode: string; remind?: boolean; // remind defaults to true
  email: string; code: string; password: string;         // code = the 6 digits emailed by /v1/auth/signup/email
}
export interface UsernameCheck { available: boolean; reason?: string }

export interface Today {
  battleId: string | null; // the BATTLE id of today's main battle (informational). Not the id used by /v1/entries/:id
  day: number;             // today's day number since launch, 1-based. Unchanged when status is "closed"
  status: BattleStatus;
  startsAt: number;        // ms. When status is "closed" these two point at TOMORROW's battle
  endsAt: number;          // ms. Last moment a player can start
  myState: { rank: number; move: number; average: number; players: number }; // rank 0 = not ranked yet
  derby: { home: string; away: string; note: string } | null;                // state NAMES
  isUprisingDay: boolean;  // true on Fridays
  myEntry: { entryId: string; finished: boolean } | null; // this player's entry for today's MAIN battle; null = not started
}

export interface Subject { id: SubjectId; name: string; leader: string; myStateRank: number }

export interface PublicQuestion {
  index: number;           // 0-based position for this player
  subject: string;         // display name, e.g. "Maths"
  text: string;
  highlight?: string;      // a substring of text to emphasise; omitted when absent
  options: [string, string, string, string];
  timeLimitSec: number;
}

export interface BattleStart { battleId: string; total: number; question: PublicQuestion; remainingMs: number }
// ^ battleId here is the ENTRY id. Use it for /current, /answers, /finish, /reveal and challenges.

export interface Resume {
  battleId: string; total: number;
  answered: boolean[];             // one per answered question, true = correct
  question: PublicQuestion | null; // null when every question is answered
  remainingMs: number;
  finished: boolean;
}

export interface AnswerResult {
  correct: boolean;
  correctIndex: number;            // index into the options array this player was shown
  points: number;                  // 0, or 100 to 150
  next: PublicQuestion | null;     // null after the last question
}

export interface BattleResult {
  day: number;
  score: number;        // correct answers
  total: number;
  answers: boolean[];   // length === total
  points: number;
  stateCode: string;
  stateRank: number;    // 0 = state not ranked yet
  stateMove: number;    // positive = moved up
  banter: string;       // server-written line, show as-is
  streak: number;
  rank: Rank;
  percentile: number;   // "Top N%", 1 to 100
}

export interface LatestEntry { battleId: string; day: number; total: number } // battleId = ENTRY id; main battle OR league

export interface TableRow {
  state: string; stateCode: string;
  players: number;
  move: number;          // positive = up; 0 for unranked and for week/all tables
  average: number;       // mean correct answers, 2 decimals
  ranked: boolean;       // false until the state has enough players
  rank: number | null;   // null when not ranked
  boosted: boolean;      // true if this state scores double in tonight's battle (Uprising). Always false on week/all
}

export interface Reveal {
  index: number; total: number; subject: string; text: string;
  options: { text: string; pct: number }[]; // same order the player saw; pct = share who chose it
  correctIndex: number;
  yourChoice: number | null;                // null = no answer or out of time
  explanation: string;
  funStat?: { headline: string; banter: string };
}

export interface Derby { day: string; home: string; away: string; status: "upcoming" | "done"; result?: string }
export interface DerbyPage {
  tonight: { home: string; away: string; region: string; homeAvg: number; awayAvg: number; headToHead: string } | null;
  week: Derby[];                                              // day = "Mon".."Sun"
  rival: { vs: string; lead: number; dayOfWeek: number } | null; // lead can be negative; 1 = Monday
}

export interface Challenge { id: string; username: string; state: string; status: "won" | "lost" | "draw" | "waiting"; score?: string }
export interface ChallengeCreated { id: string; url: string }

export interface Badge { name: string; earned: boolean }
export interface StatsPage {
  player: PlayerSummary;
  history: number[];                           // last 14 scores, oldest first
  accuracy: { subject: string; pct: number }[]; // best first; only subjects with 3+ answers
  badges: Badge[];
  placesPushed: number;
}
```

Optional fields (`highlight`, `funStat`, `reason`, `score`, `result`, `details`) are omitted, not sent as `null`.

## 4. Endpoints

| Method | Path | Auth | Success |
|---|---|---|---|
| POST | `/v1/usernames/check` | public | 200 `UsernameCheck` |
| POST | `/v1/auth/signup/email` | public | 204 |
| POST | `/v1/auth/signup` | public | 201 `GuestSession` |
| POST | `/v1/auth/logout` | yes | 204 |
| POST | `/v1/auth/login` | public | 200 `GuestSession` |
| POST | `/v1/auth/password/forgot` | public | 204 |
| POST | `/v1/auth/password/reset` | public | 200 `GuestSession` |
| GET | `/v1/auth/account` | yes | 200 `AccountStatus` |
| POST | `/v1/auth/account/email` | yes | 204 |
| POST | `/v1/auth/account/confirm` | yes | 200 `AccountStatus` |
| POST | `/v1/auth/password/change` | yes | 204 |
| GET | `/v1/me` | yes | 200 `PlayerSummary` |
| PATCH | `/v1/me` | yes | 200 `PlayerSummary` |
| GET | `/v1/today` | yes | 200 `Today` |
| GET | `/v1/subjects` | yes | 200 `Subject[]` |
| POST | `/v1/entries` | yes | 201 `BattleStart` |
| GET | `/v1/entries/latest` | yes | 200 `LatestEntry` |
| GET | `/v1/entries/:id/current` | yes | 200 `Resume` |
| POST | `/v1/entries/:id/answers` | yes | 200 `AnswerResult` |
| POST | `/v1/entries/:id/finish` | yes | 200 `BattleResult` |
| GET | `/v1/entries/:id/result` | yes | 200 `BattleResult` |
| GET | `/v1/entries/:id/reveal/:n` | yes | 200 `Reveal` |
| GET | `/v1/table` | public | 200 `TableRow[]` |
| GET | `/v1/derbies` | yes | 200 `DerbyPage` |
| GET | `/v1/challenges` | yes | 200 `Challenge[]` |
| POST | `/v1/challenges` | yes | 201 `ChallengeCreated` |
| POST | `/v1/challenges/:id/accept` | yes | 204 |
| GET | `/v1/stats` | yes | 200 `StatsPage` |

### Accounts

**`POST /v1/usernames/check`** `{ username }`

Always 200 for a string up to 32 characters. Call it (debounced) as the player types.

```json
{ "available": false, "reason": "Use 3 to 16 letters, numbers, dots or underscores." }
```

Rules: 3 to 16 characters from `A-Z a-z 0-9 . _`. Uniqueness is case-insensitive. Reserved: admin, support, statewars, moderator, official, help, root, system. You can mirror the pattern client-side with `/^[A-Za-z0-9._]{3,16}$/`.

Every account has a username, a state, an email and a password. There are no guest accounts: `POST /v1/auth/guest` now answers 410 `gone`. The type is still named `GuestSession` only so existing client code keeps compiling.

Rules shared by sign-up, sign-in and recovery: emails are trimmed and lower-cased by the server. Passwords are 8 to 72 characters and are never trimmed. Codes are 6 digits, sent as a **string** (`"042917"`, leading zeros matter), valid for 10 minutes, single use, and destroyed after 5 wrong tries.

**`POST /v1/auth/signup/email`** `{ email }` — sign-up step 1. Public. Emails a 6-digit code to that address. 204.

- Always 204 for a well-formed email, including one that already has an account, so this step never reveals who is registered. Nothing is created yet.
- Asking again replaces the earlier code. 429 `rate_limited` if asked twice for the same address within a minute ("Wait a minute before asking for another code."). 400 for a malformed email.

**`POST /v1/auth/signup`** `{ username, stateCode, remind?, email, code, password }` — sign-up step 2. Public. Creates the account and signs the player in.

- `username` is trimmed and `stateCode` upper-cased by the server. `remind` defaults to `true`. `email` must be the address the code was sent to.
- 201:

```json
{ "token": "884|Pixqd9Mj…", "player": { "username": "tobi_codes", "stateCode": "LA", "rank": "Olodo",
  "pointsToNext": 2000, "streak": 0, "bestStreak": 0, "battles": 0, "average": 0 } }
```

- Errors, in the order the server checks them:
  - 400 `bad_request` with `details` for a missing or malformed field (short password, code that isn't 6 digits, 3-letter state).
  - 409 `username_unavailable`, or 400 `bad_request` "Pick one of the 36 states or the FCT." These are checked **before** the code, and do not use it up: let the player fix the name and submit again with the same code.
  - 400 `invalid_code`.
  - 409 `email_taken` ("That email already has an account. Sign in instead."). This uses the code up. Send the player to sign-in or to "Forgot password?".
- Check the username with `/v1/usernames/check` before step 1, so the player is not sent a code for a name they cannot have.

Treat the token as an opaque string and store it (for example in `localStorage`). Losing it is not fatal: the player signs in again with their password, or resets it by email.

**`POST /v1/auth/logout`** — 204, no body. Ends this session only. The player can sign in again at any time.

### Sign-in and recovery

**`GET /v1/auth/account`** — the email on the account. Use it to show the player which address they signed up with.

```json
{ "secured": true, "email": "tobi@example.com" }
```

`secured` is `true` for every account made through sign-up. `{ "secured": false }` (no `email`) can only come from a guest account created before sign-up required an email; there are none in production. Two endpoints exist to give such an account an email and password, `POST /v1/auth/account/email` `{ email }` and `POST /v1/auth/account/confirm` `{ email, code, password }`. They work like the two sign-up steps and answer 409 `already_secured` for any normal account. **Do not build UI for them.**

**`POST /v1/auth/login`** `{ identifier, password }` — public. `identifier` is the username or the email, case-insensitive.

- 200 `GuestSession` (`{ token, player }`), the same shape as sign-up. Store the token exactly as after sign-up. Other devices stay signed in.
- 401 `invalid_credentials` ("Wrong username, email or password.") for every kind of failure, on purpose: do not try to tell the cases apart.
- 429 `rate_limited` after 10 tries for the same name in 15 minutes ("Too many tries. Wait 15 minutes, or reset your password."). Link to the reset flow from this message.

**`POST /v1/auth/password/forgot`** `{ email }` — public. Always 204, whether or not that email has an account, and at most one email per minute. Always show the same "If that email has an account, we've sent a code" message.

**`POST /v1/auth/password/reset`** `{ email, code, password }` — public. Sets the new password.

- 200 `GuestSession`: the player is signed in on this device. Every other session of the account is ended (their next request gets 401 `unauthorized`).
- Errors: 400 `invalid_code`; 400 `bad_request` with `details`.

**`POST /v1/auth/password/change`** `{ currentPassword, newPassword }` — for a signed-in player. 204.

- The current device stays signed in; every other session is ended.
- Errors: 409 `wrong_password`; 409 `not_secured`; 429 after 10 wrong tries in 15 minutes; 400 for a short new password.

There is no endpoint to change the email or username of an account.

### Profile

**`GET /v1/me`** — 200 `PlayerSummary`.

**`PATCH /v1/me`** `{ stateCode?, remind? }` — at least one is required. Returns the updated `PlayerSummary`.

- A player's first state change after sign-up is free. After that, one change per 30 days, otherwise 409 `state_change_cooldown` ("You can change your state again on 2026-11-10.").
- `remind` is stored but nothing sends reminders yet (Web Push is not built).

### Home

**`GET /v1/today`**

```json
{ "battleId": "bc92292b-feea-4c11-bf5a-452468b6fc65", "day": 11, "status": "upcoming",
  "startsAt": 1791745200000, "endsAt": 1791746100000,
  "myState": { "rank": 0, "move": 0, "average": 0, "players": 0 },
  "derby": { "home": "Ekiti", "away": "Ondo", "note": "First derby between these two." },
  "isUprisingDay": false,
  "myEntry": { "entryId": "c891e597-4d3d-4948-9c0c-b0906022125a", "finished": true } }
```

- `status`: `upcoming` before `startsAt`; `live` from `startsAt` until `endsAt`; `closed` after.
- `myEntry` is this player's entry for **today's main battle**, never a league entry. It is `null` until they start it, `finished: false` while they are playing (or have abandoned it), and `finished: true` once it is finished, whether by them or by the server. `entryId` is the id for every `/v1/entries/:id/...` call. It comes from the server, so it survives signing in on another phone.
- Count down with a local timer; re-fetch when it reaches zero and when the tab regains focus. Do not poll every second.

What each field describes in each state:

| Field | `upcoming` | `live` | `closed` (until midnight WAT) |
|---|---|---|---|
| `startsAt`, `endsAt` | today's battle | today's battle | **tomorrow's** battle |
| `day`, `battleId` | today's battle | today's battle | still today's battle, the one that just closed |
| `myEntry` | `null` | today's battle | still today's battle |
| `myState` | all zeros | tonight's live position | still tonight's table; final once the battle is finalized at about 20:27 |
| `derby`, `isUprisingDay` | today | today | today |

Only `startsAt` and `endsAt` look ahead, so one countdown to `startsAt` works in both the `upcoming` and `closed` states. Everything else changes together at 00:00 WAT, when `day` goes up by one and `myEntry` returns to `null`.

`myState.rank` is 0 when the state is not ranked yet. `battleId` is `null` only if today's battle has not been scheduled, in which case `myEntry` is `null` too.

**`GET /v1/subjects`** — the six subject leagues.

```json
[ { "id": "english", "name": "English", "leader": "Not started", "myStateRank": 0 } ]
```

`leader` is a state name, or the literal text `"Not started"` or `"Not enough players yet"`. `myStateRank` is 0 when unranked.

### Battle

**`POST /v1/entries`** `{ league? }` — start, or resume, a battle.

- No body (or `{}`) starts tonight's main battle. `{ "league": "maths" }` starts a subject league battle; valid values are the six `SubjectId`s.
- 201 `BattleStart`:

```json
{ "battleId": "392e8e6e-8f0b-4fae-af8c-053a6a9b0fa3", "total": 10,
  "question": { "index": 0, "subject": "Maths", "text": "If 3x + 5 = 20, what is x?",
                "options": ["3", "5", "15", "25"], "timeLimitSec": 60 },
  "remainingMs": 59961 }
```

- Calling it again mid-battle returns the same entry at its current question with the true `remainingMs`. It never restarts the clock.
- Errors: 425 `too_early` (not open); 409 `already_played`; 409 `already_answered` (call finish); 400 for an invalid `league`.

**`GET /v1/entries/:id/current`** — where the player is now. Use it after a reload or reconnect.

```json
{ "battleId": "392e…", "total": 10, "answered": [true, false], "question": { "index": 2, "…": "…" },
  "remainingMs": 41200, "finished": false }
```

- When all questions are answered: `question: null`, `remainingMs: 0`. `finished` is true only after finish.
- Connection-loss allowance: if the current question has under 10 seconds left (or has expired) when this is called, the server tops it back up to 10 seconds, drawing on a 30-second budget per battle. Trust the returned `remainingMs`.

**`POST /v1/entries/:id/answers`** `{ index, choice }`

- `index`: the question's `index`. `choice`: 0 to 3, the position in the `options` array as shown; or `null` for "no answer" (time ran out). `choice` must be present, even when null.
- 200 `AnswerResult`:

```json
{ "correct": true, "correctIndex": 1, "points": 150,
  "next": { "index": 1, "subject": "History", "text": "In which year did Nigeria become a republic?",
            "options": ["1966", "1963", "1960", "1979"], "timeLimitSec": 60 } }
```

- **Idempotent.** Re-sending an already answered `index` returns the stored result, whatever `choice` the retry carries. Retry freely on network failure.
- Late answers (more than 2.5 seconds past the limit) are accepted and scored as wrong with 0 points; the response still carries `correctIndex` and `next`.
- Errors: 409 `out_of_order`; 409 `finished`; 400 `bad_request`; 404 `not_found` (not this player's entry).

**`POST /v1/entries/:id/finish`** — locks in the result. Idempotent.

```json
{ "day": 11, "score": 2, "total": 10,
  "answers": [true, false, false, false, true, false, false, false, false, false],
  "points": 300, "stateCode": "LA", "stateRank": 6, "stateMove": 8,
  "banter": "Osun is 1st tonight. Who took the light during revision?",
  "streak": 1, "rank": "Olodo", "percentile": 78 }
```

- Error: 409 `not_done` if questions remain while the battle is still running.
- An abandoned entry is finished by the server about 12 minutes after the window closes, with unanswered questions counted wrong.
- Calling it on an entry that is already finished, by the player or by the server, changes nothing and returns the result. That is safe and intended, so a retry after a lost response is fine. To *read* a result, prefer the endpoint below: it can never finish anything.

**`GET /v1/entries/:id/result`** — the `BattleResult` of a finished entry. Read-only. Same body as finish.

- 409 `not_finished` ("This battle isn't finished yet.") if the entry is still open; 404 if it is not this player's entry.
- Use it for the result screen whenever the entry was finished earlier: after a reload, on another device, or for an entry the server auto-finished.

What a result holds, from either endpoint:

| Fields | Behaviour |
|---|---|
| `day`, `score`, `total`, `answers`, `points`, `stateCode` | Fixed for good once the entry is finished |
| `stateRank`, `stateMove`, `banter`, `percentile` | The table **as it stands when you ask**, not as it was when the player finished. They keep moving while other players finish, and are final from about 20:27 WAT, when the battle is finalized. Re-fetch rather than cache if the screen stays open during the battle |
| `streak`, `rank` | The player's **current** streak and rank, not those of that day |

Nothing in a result expires. Once the battle is finalized, `stateRank`, `stateMove`, `banter` and `percentile` are read from the permanent records and stay the same however old the entry is, so any past result can be shown in full.

**`GET /v1/entries/latest`** — the player's most recent finished entry, main battle **or league**. Use it for "your last result", not to decide whether today's main battle was played (use `today.myEntry` for that).

```json
{ "battleId": "392e8e6e-8f0b-4fae-af8c-053a6a9b0fa3", "day": 11, "total": 10 }
```

404 `not_found` means the player has never finished a battle. That is a normal state for a new player, not a failure.

**`GET /v1/entries/:id/reveal/:n`** — "How Nigeria answered" for question `n` (0-based, this player's order).

```json
{ "index": 0, "total": 10, "subject": "Maths", "text": "If 3x + 5 = 20, what is x?",
  "options": [ { "text": "3", "pct": 26 }, { "text": "5", "pct": 24 }, { "text": "15", "pct": 25 }, { "text": "25", "pct": 25 } ],
  "correctIndex": 1, "yourChoice": 1, "explanation": "3x = 20 − 5 = 15, so x = 15 ÷ 3 = 5." }
```

- Locked until every player could have finished: with default settings that is 20:26:55 WAT, 11 minutes 55 seconds after the window closes. Before then: 425 `too_early` with a message such as "Answers unlock at 20:26 once everyone has finished." Show the message; re-check when the screen is reopened.
- 404 `not_found` if `n` is at or past `total`. Fetch one question at a time as the player pages through.

### Table

**`GET /v1/table?period=tonight|week|all&subject=all|english|maths|science|gist|sports|music`**

Public (no token needed). Defaults: `period=tonight`, `subject=all`. Always returns all 37 states: ranked states first in rank order, then unranked ones.

```json
[ { "state": "Osun", "stateCode": "OS", "players": 62, "move": 7, "average": 2.79, "ranked": true, "rank": 1, "boosted": false },
  { "state": "FCT",  "stateCode": "FC", "players": 62, "move": 24, "average": 2.74, "ranked": true, "rank": 2, "boosted": false } ]
```

- Identical for every player. Highlight the player's own state client-side by matching `stateCode` to `PlayerSummary.stateCode`.
- A state is unranked until it has enough players (200 in production). Show unranked states without a position.
- `average` is correct answers per player (0 to 10).
- `boosted` marks the states whose scores count double toward tonight's average (the Uprising). The set is fixed when the battle is scheduled: on Fridays it is the bottom 10 ranked states of Thursday's table, which is not the same as the bottom 10 of tonight's table, so draw the "Uprising zone" from `boosted` and never from position. It is `false` on every row on other days, before Friday's battle is scheduled, and always on `week` and `all`. Boosted rows are not grouped together; they sit wherever their average puts them.
- `move` is places gained since the previous battle; it is always 0 on `week` and `all`.
- Before tonight's battle exists, `tonight` returns every state with zeros.
- Server sends `Cache-Control: public, max-age=5` for `tonight` and `max-age=60` for `week`/`all`. Poll no faster than every 5 seconds while live, and only while the table is on screen.

### Derbies, challenges, stats

**`GET /v1/derbies`**

```json
{ "tonight": { "home": "Ekiti", "away": "Ondo", "region": "South West", "homeAvg": 7.4, "awayAvg": 6.6, "headToHead": "First meeting." },
  "week": [ { "day": "Sat", "home": "FCT", "away": "Nasarawa", "status": "done", "result": "Nasarawa won 7.5–5.8" },
            { "day": "Sun", "home": "Ekiti", "away": "Ondo", "status": "upcoming" } ],
  "rival": { "vs": "Borno", "lead": -0.1, "dayOfWeek": 7 } }
```

`tonight` and `rival` can be `null`. `result` can be the literal `"No result"`. All text fields are ready to display.

`homeAvg` and `awayAvg` are each state's **average this week**: correct answers per player across the main battles finalized since Monday (WAT), weighted by players, the same number as that state's `average` in `GET /v1/table?period=week`. They do not include tonight's live scores; tonight is added once it is finalized at about 20:27. They do not change when the battle opens. A state with no finalized result this week shows 0, so on Monday both are 0 until that night's battle is finalized. Label them "This week's average", and treat 0 as "no result yet" rather than a score.

`rival.lead` uses the same weekly averages (the player's state minus the rival's, 1 decimal).

**`POST /v1/challenges`** `{ entryId }` — `entryId` is a finished entry of the player's own. 201:

```json
{ "id": "178b0733-08cd-4d62-81b3-de7583923e06", "url": "http://localhost:5173/?challenge=178b0733-08cd-4d62-81b3-de7583923e06" }
```

Share `url`. Errors: 404 (not their entry), 409 `not_finished`.

**`POST /v1/challenges/:id/accept`** — 204. Errors: 400 (own challenge), 404, 409 `challenge_taken`. Accepting twice is fine.

**`GET /v1/challenges`** — the player's 20 most recent, sent or received.

```json
[ { "id": "178b…", "username": "Waiting for a friend", "state": "", "status": "waiting" } ]
```

`status` stays `waiting` until both players have finished the same battle; then it is `won`/`lost`/`draw` from the viewer's side and `score` appears as `"8–6"` (higher first). Challenges are bragging rights only and never affect the table.

**`GET /v1/stats`**

```json
{ "player": { "…": "PlayerSummary" }, "history": [2], "accuracy": [],
  "badges": [ { "name": "Perfect 10", "earned": false }, { "name": "Week Warrior", "earned": false },
              { "name": "Uprising Hero", "earned": false }, { "name": "30-day streak", "earned": false },
              { "name": "First battle", "earned": true } ],
  "placesPushed": 0 }
```

`history` and `accuracy` can be empty; design the empty states.

## 5. Flows

### App start

1. No stored token: show a welcome screen with two paths, "Create account" and "I have an account".
2. Stored token: `GET /v1/me`. On 401 `unauthorized`, clear the token and show the welcome screen.
3. If the URL has `?challenge=<uuid>`, keep the id through sign-up or sign-in and call `POST /v1/challenges/:id/accept` once signed in. Ignore `bad_request` (own challenge).

### Creating an account

Collect everything first, then verify the email last, so the code is fresh when it is needed:

1. Username (checked live with `/v1/usernames/check`), state picker, reminder toggle, email, password.
2. `POST /v1/auth/signup/email` with the email. Show "We've sent a 6-digit code to <email>", a code field, a "Send a new code" action (enabled after 60 seconds) and a way back to change the email.
3. `POST /v1/auth/signup` with every field plus the code. On 201, store the token and enter the app.
4. On 409 `username_unavailable`, go back to the username field; the same code still works. On 400 `invalid_code`, keep the code field and offer a new code. On 409 `email_taken`, offer "Sign in instead".

There is no way to play without an account, so do not offer "play as guest" or "skip for now".

### Signing in

Username or email plus password, `POST /v1/auth/login`, with a "Forgot password?" link. On 200, store the token and enter the app.

### Forgotten password

1. Email field, then `POST /v1/auth/password/forgot`. Always move to step 2 with the neutral message.
2. Code plus new password, then `POST /v1/auth/password/reset`. Store the returned token and enter the app.

### Has the player played today?

Read `myEntry` from `GET /v1/today`. It is about the main battle only and comes from the server, so no local storage is needed:

| `myEntry` | `status` | Show |
|---|---|---|
| `null` | `upcoming` | Countdown |
| `null` | `live` | Play button |
| `null` | `closed` | "You missed tonight", countdown to tomorrow |
| `finished: false` | any | Resume: `GET /v1/entries/:id/current` with `myEntry.entryId`, then continue, or call finish if `question` is `null` |
| `finished: true` | any | Result: `GET /v1/entries/:id/result` with `myEntry.entryId` |

If `POST /v1/entries` answers 409 `already_played` or `already_answered`, re-fetch `/v1/today` and follow the table: the entry id is in `myEntry`.

League battles are not covered by `myEntry`. For those, keep the entry id from `POST /v1/entries` and treat 409 `already_played` as "done".

### Playing a battle

```
POST /v1/entries                      → entryId, question 0, remainingMs
loop:
  show question; run a countdown from remainingMs
  on tap:      POST /answers { index, choice }
  on timeout:  POST /answers { index, choice: null }
  response:    show correct/wrong using correctIndex and points
  next !== null → continue with next
  next === null → POST /finish → result screen
```

Rules the UI must respect:

- **The server owns the clock.** The countdown is display only. The clock for question N+1 starts on the server at the moment it records the answer to question N, and `AnswerResult` carries no `remainingMs`. So any time spent showing "correct!" feedback comes out of the next question's 60 seconds. Keep the feedback short (about a second), start the next countdown from `timeLimitSec` as soon as the response arrives, and call `GET /current` if an exact value is ever needed.
- **Never guess the answer.** The correct option is only known from `correctIndex` in the response. Disable the options while the request is in flight.
- **Options are in this player's own order**, and questions are too. Send back the position tapped; never sort or re-shuffle the array, and never compare indexes between players.
- **Retry on network failure** with the same `{ index, choice }`. It cannot double-score.
- **On reload or reconnect**, take the entry id from `today.myEntry` and call `GET /v1/entries/:id/current` (or just `POST /v1/entries` again). Then: `finished` → result; `question === null` → call finish; otherwise continue from `question` with the returned `remainingMs`.
- **On `out_of_order` or `not_done`**, resync with `GET /current` rather than showing an error.
- Send the timeout answer (`choice: null`) promptly: a tap that arrives within 2.5 seconds after the limit still counts, later ones score 0.

### After the battle

- Result screen from `BattleResult`: score, `answers` dots, points, state rank and movement, `banter`, streak, rank, "Top `percentile`%".
- "How Nigeria answered" (`/reveal/:n`) is locked until about 20:27 WAT. Show the 425 message as a locked state.
- "Challenge a friend": `POST /v1/challenges` with the entry id, then share `url` (Web Share API with a copy fallback).
- Table: poll `period=tonight` every 5 seconds or slower while visible.

## 6. Scoring and labels (for copy and UI)

- Correct answer: 100 points plus a speed bonus of up to 50 (instant = 150, at the limit = 100). Wrong, no answer or too late: 0.
- `score` is the count of correct answers; `points` is the sum above. State averages use `score`.
- Ranks by lifetime points: Olodo 0, Jambite 2,000, Average Joe 8,000, Efiko 25,000, Professor 60,000. These thresholds are placeholders on the server; always display `rank` and `pointsToNext` from the API rather than computing them.
- Badges: Perfect 10 (all correct), Week Warrior (7-day streak), 30-day streak, Uprising Hero (80%+ for a boosted state on Friday), First battle.
- A streak shows as 0 once a day has been missed.

## 7. Local development

- Start the API from the API repo with `.\serve-windows.ps1`; it listens on port 8080. PostgreSQL and Redis must be running.
- The local `.env` has `DEV_ALWAYS_OPEN=true`, which changes behaviour the client will notice:
  - `POST /v1/entries` works at any time of day, even while `/v1/today` reports `upcoming` or `closed`. For local testing, let the Play button work regardless of `status` (behind a dev flag).
  - Reveal is available immediately, and finish is allowed before every question is answered.
  - `myState` stays all zeros while `/v1/today` reports `upcoming`, even after you have played, because it only fills in from the real opening time. `myEntry` is correct at any hour.
- `MIN_PLAYERS_RANKED=1` locally, so states rank with a single player. In production it is 200, so most states will be unranked early on. Test the unranked UI.
- The seeded database has one battle per day with 10 questions and no league battles, so league starts return 425 and `/v1/subjects` shows "Not started".
- No email is really sent locally. Each one is written to `storage/logs/mail.log` in the API repo; read the 6-digit code from the newest entry there. Emails are sent by a background worker, so the code appears a moment after the 204, and only if the API's queue worker is running. If no code shows up, that worker is the first thing to check.
- A player can play the daily battle once. To play again locally, create another account (mind the 20 per hour limit). Any address works, since nothing is really sent: use a different made-up email each time, and read its code from `storage/logs/mail.log`.
- In production, `POST /v1/entries` returns 425 outside 20:00 to 20:15 WAT.

## 8. Reference data

There is no endpoint for states or subjects; hard-code them.

**States** (`code` name, region):

| Region | States |
|---|---|
| North Central | `BE` Benue, `FC` FCT, `KO` Kogi, `KW` Kwara, `NA` Nasarawa, `NI` Niger, `PL` Plateau |
| North East | `AD` Adamawa, `BA` Bauchi, `BO` Borno, `GO` Gombe, `TA` Taraba, `YO` Yobe |
| North West | `JI` Jigawa, `KD` Kaduna, `KN` Kano, `KT` Katsina, `KE` Kebbi, `SO` Sokoto, `ZA` Zamfara |
| South East | `AB` Abia, `AN` Anambra, `EB` Ebonyi, `EN` Enugu, `IM` Imo |
| South South | `AK` Akwa Ibom, `BY` Bayelsa, `CR` Cross River, `DE` Delta, `ED` Edo, `RI` Rivers |
| South West | `EK` Ekiti, `LA` Lagos, `OG` Ogun, `ON` Ondo, `OS` Osun, `OY` Oyo |

**Subject leagues** (`id` name): `english` English, `maths` Maths, `science` Science, `gist` Gist and news, `sports` Sports, `music` Music and Nollywood. Questions can also carry the subjects History and Geography, which have no league.

## 9. Suggested API client

```ts
const BASE = import.meta.env.VITE_API_URL ?? "http://127.0.0.1:8080";

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string,
              public details?: { field: string; message: string }[]) { super(message); }
}

export async function api<T>(method: string, path: string, body?: unknown): Promise<T> {
  const token = localStorage.getItem("sw_token");
  const headers: Record<string, string> = {};
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers["Content-Type"] = "application/json";

  let res: Response;
  try {
    res = await fetch(BASE + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  } catch {
    throw new ApiError(0, "network", "No connection. Check your network and try again.");
  }
  if (res.status === 204) return undefined as T;

  const json = await res.json().catch(() => null);
  if (!res.ok) {
    const e = (json as ApiErrorBody | null)?.error;
    if (e?.code === "unauthorized") localStorage.removeItem("sw_token"); // then route to the welcome screen
    throw new ApiError(res.status, e?.code ?? "internal", e?.message ?? "Something went wrong. Try again.", e?.details);
  }
  return json as T;
}
```

Only set `Content-Type` when there is a body, and always `JSON.stringify` it. Retry `network` errors on the answer and finish calls; they are idempotent.

## 10. Not available yet

Do not build UI that depends on these; the endpoints do not exist:

- Phone or SMS sign-in, and social sign-in. Sign-in is by username or email plus password only.
- Changing the email of an account.
- Guest play. Every player must create an account with an email and password.
- Share images (share the challenge `url` or plain text instead).
- Web Push reminders. `remind` is stored by sign-up and `PATCH /v1/me`, but nothing reads it yet and no endpoint returns it, so offer the reminder toggle only on the sign-up form, not as a setting afterwards. Plan: when reminders are built, `remind: boolean` will be added to `PlayerSummary`.
- Reporting and moderation.
- Changing a username.

## 11. Admin endpoints

For a separate admin tool only; players get 403 `forbidden`. They need a token belonging to a user with the admin role, and there is no HTTP endpoint that issues one.

| Method | Path | Body or query | Success |
|---|---|---|---|
| GET | `/v1/admin/questions` | `?status=draft\|approved\|retired&subject=&limit=1..100 (50)&offset=0` | 200 question rows |
| POST | `/v1/admin/questions` | `{ subject, text (10–300), highlight? (≤40), options: [4 strings, 1–80 each], answer: 0..3, explanation (5–300), funHeadline? (≤80), funBanter? (≤120) }` | 201 question row (draft) |
| POST | `/v1/admin/questions/:id/approve` | none | 200 question row |
| POST | `/v1/admin/battles` | `{ date: "YYYY-MM-DD", kind: "daily"\|"league", subject?: string\|null, questionIds?: uuid[1..20] }` | 201 battle |
| POST | `/v1/admin/days/:date/prepare` | none | 200 `{ created: string[], skipped: string[] }` |

- Question rows are **snake_case**, unlike the rest of the API: `id, subject, text, highlight, options, answer, explanation, fun_headline, fun_banter, status, author_id, approved_by, created_at` (ISO string). They include the answer.
- `subject` is one of the eight subject ids (the six leagues plus `history`, `geography`). The four options must differ; `highlight` must appear in `text`.
- Approval must be by a different admin than the author (403 otherwise); only drafts can be approved (409 `not_draft`).
- Battle response: `{ id, date, kind, subject, dayNumber, opensAt, closesAt, questionIds, uprisingStates, status }`. Scheduling without `questionIds` picks 10 fresh approved questions, or returns 409 `not_enough_questions`. Scheduling an existing slot returns the existing battle.
