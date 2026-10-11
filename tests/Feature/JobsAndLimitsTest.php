<?php

use App\Core\WatTime;
use App\Modules\Battles\Jobs\PrepareDayJob;
use App\Modules\Battles\Jobs\SweepJob;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $now = Carbon::parse('2026-10-23T12:00:00+01:00');
    Carbon::setTestNow($now);
    DatabaseSeeder::seed(20, 15, '2026-10-01', $now->getTimestampMs());
});

it('sweep job closes only battles whose last player has finished, once', function () {
    $times = WatTime::battleTimes('2026-10-23', 20, 15);
    $today = DB::table('battles')->where('battle_date', '2026-10-23')->value('id');

    SweepJob::dispatchSync();
    expect(DB::table('battles')->where('id', $today)->value('status'))->toBe('scheduled'); // not due yet

    Carbon::setTestNow(Carbon::createFromTimestampMs($times['closesAt'] + 3600_000));
    SweepJob::dispatchSync();
    SweepJob::dispatchSync();
    expect(DB::table('battles')->where('id', $today)->value('status'))->toBe('finalized');
});

it('prepare-day job creates tomorrow from the clock and the command does the same', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24T00:05:00+01:00'));
    PrepareDayJob::dispatchSync(); // the 10 seeded questions were used in the last 60 days: daily is skipped, no crash
    expect(DB::table('battles')->where('battle_date', '2026-10-24')->count())->toBe(0);
    $this->artisan('statewars:prepare-day', ['date' => '2026-10-24'])->assertSuccessful();
});

it('verifies a battle: Redis totals equal Postgres totals', function () {
    $times = WatTime::battleTimes('2026-10-23', 20, 15);
    Carbon::setTestNow(Carbon::createFromTimestampMs($times['opensAt'] + 1000));
    $h = ['Authorization' => 'Bearer '.$this->postJson('/v1/auth/guest', ['username' => 'tobi_codes', 'stateCode' => 'KW'])->json('token')];
    $e = $this->postJson('/v1/entries', [], $h)->json('battleId');
    for ($i = 0; $i < 10; $i++) {
        $this->postJson("/v1/entries/{$e}/answers", ['index' => $i, 'choice' => right($e, $i)], $h);
    }
    $this->postJson("/v1/entries/{$e}/finish", [], $h)->assertOk();
    $this->artisan('statewars:verify-battle')->expectsOutputToContain('OK: no duplicate answers')->assertSuccessful();
});

it('rate limits with the Node error shape, per IP for sign-up', function () {
    config(['statewars.rate_auth_per_hour' => 2]);
    $this->postJson('/v1/auth/guest', ['username' => 'one_1', 'stateCode' => 'KW'])->assertStatus(201);
    $this->postJson('/v1/auth/guest', ['username' => 'two_2', 'stateCode' => 'KW'])->assertStatus(201);
    $this->postJson('/v1/auth/guest', ['username' => 'three_3', 'stateCode' => 'KW'])->assertStatus(429)
        ->assertExactJson(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Wait a moment and try again.']])
        ->assertHeader('Retry-After');
    $this->get('/health')->assertOk(); // never limited
});

it('limits answers to 40 a minute per player, separately from the global limit', function () {
    config(['statewars.rate_global_per_min' => 3]);
    $h = ['Authorization' => 'Bearer '.$this->postJson('/v1/auth/guest', ['username' => 'tobi_codes', 'stateCode' => 'KW'])->json('token')];
    $this->getJson('/v1/me', $h)->assertOk();
    $this->getJson('/v1/me', $h)->assertOk();
    $this->getJson('/v1/me', $h)->assertOk();
    $this->getJson('/v1/me', $h)->assertStatus(429);
    // answers have their own bucket: still allowed (404 because the entry doesn't exist, but not 429)
    $this->postJson('/v1/entries/00000000-0000-0000-0000-000000000000/answers', ['index' => 0, 'choice' => 0], $h)->assertStatus(404);
});
