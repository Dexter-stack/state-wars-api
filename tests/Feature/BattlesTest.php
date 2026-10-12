<?php

use App\Core\WatTime;
use App\Modules\Battles\Repositories\Contracts\EntriesRepository;
use App\Modules\Battles\Repositories\EntryAnswerRow;
use App\Modules\Battles\Services\BattlesService;
use App\Modules\Battles\Support\Scoring;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

// Real PostgreSQL + Redis. The seeded battle is "today" at 20:00 WAT; tests travel into its window.

beforeEach(function () {
    $now = Carbon::parse('2026-10-23T12:00:00+01:00'); // a Friday
    Carbon::setTestNow($now);
    $this->seed = DatabaseSeeder::seed(20, 15, '2026-10-01', $now->getTimestampMs());
    $this->times = WatTime::battleTimes('2026-10-23', 20, 15);
    $this->battleId = DB::table('battles')->where('battle_date', '2026-10-23')->value('id');
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['opensAt'] + 1000));
});

function player(string $name = 'tobi_codes', string $state = 'KW'): array
{
    $t = test()->postJson('/v1/auth/guest', ['username' => $name, 'stateCode' => $state])->assertStatus(201)->json('token');

    return ['Authorization' => "Bearer {$t}"];
}

it('refuses to start outside the window', function () {
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['opensAt'] - 60_000));
    $this->postJson('/v1/entries', [], player())->assertStatus(425)
        ->assertJsonPath('error.code', 'too_early')->assertJsonPath('error.message', "This battle isn't open right now. Battles open at 8pm.");
});

it('starts, resumes the same entry and never leaks the answer', function () {
    $h = player();
    $r = $this->postJson('/v1/entries', [], $h)->assertStatus(201);
    expect(array_keys($r->json()))->toBe(['battleId', 'total', 'question', 'remainingMs'])
        ->and(array_keys($r->json('question')))->not->toContain('answer')
        ->and($r->json('question.timeLimitSec'))->toBe(60)
        ->and($r->json('total'))->toBe(10)
        ->and($r->json('remainingMs'))->toBe(60_000);
    $this->call('POST', '/v1/entries', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $h['Authorization']], '')
        ->assertStatus(201)->assertJsonPath('battleId', $r->json('battleId')); // empty JSON body = no body
});

it('plays a full battle: idempotent answers, exactly-once finish, reveal lock', function () {
    $h = player();
    $entry = $this->postJson('/v1/entries', [], $h)->json('battleId');

    $this->postJson("/v1/entries/{$entry}/answers", ['index' => 3, 'choice' => 0], $h)->assertStatus(409)->assertJsonPath('error.code', 'out_of_order');
    $this->postJson("/v1/entries/{$entry}/finish", [], $h)->assertStatus(409)->assertJsonPath('error.code', 'not_done');

    $first = null;
    for ($i = 0; $i < 10; $i++) {
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $r = $this->postJson("/v1/entries/{$entry}/answers", ['index' => $i, 'choice' => right($entry, $i)], $h)->assertOk();
        expect($r->json('correct'))->toBeTrue()->and($r->json('points'))->toBe(146); // 100 + round(50 × 55/60)
        $first ??= $r->json();
    }
    expect($r->json('next'))->toBeNull();
    $replay = $this->postJson("/v1/entries/{$entry}/answers", ['index' => 0, 'choice' => (right($entry, 0) + 1) % 4], $h)->assertOk();
    expect($replay->json())->toBe($first);
    expect(DB::table('entry_answers')->where('entry_id', $entry)->count())->toBe(10);

    $this->getJson("/v1/entries/{$entry}/current", $h)->assertOk()
        ->assertJsonPath('question', null)->assertJsonPath('finished', false)->assertJsonCount(10, 'answered');

    $f1 = $this->postJson("/v1/entries/{$entry}/finish", [], $h)->assertOk();
    $f2 = $this->postJson("/v1/entries/{$entry}/finish", [], $h)->assertOk();
    expect(array_keys($f1->json()))->toBe(['day', 'score', 'total', 'answers', 'points', 'stateCode', 'stateRank', 'stateMove', 'banter', 'streak', 'rank', 'percentile']);
    expect($f1->json('score'))->toBe(10)->and($f2->json('score'))->toBe(10)
        ->and($f1->json('points'))->toBe(1460)->and($f1->json('day'))->toBe(23)
        ->and($f1->json('stateRank'))->toBe(1)->and($f1->json('percentile'))->toBe(100)->and($f1->json('streak'))->toBe(1);
    // leaderboard, streak and badges exactly once
    expect(Redis::connection()->hget("lb:{$this->battleId}:cnt", 'KW'))->toBe('1');
    expect(DB::table('users')->where('username', 'tobi_codes')->value('points'))->toBe(1460);
    expect(DB::table('user_badges')->pluck('badge')->sort()->values()->all())->toBe(['first_battle', 'perfect_10']);

    $this->getJson("/v1/entries/{$entry}/reveal/0", $h)->assertStatus(425)->assertJsonPath('error.code', 'too_early');
    $this->postJson('/v1/entries', [], $h)->assertStatus(409)->assertJsonPath('error.code', 'already_played');
    $this->getJson('/v1/entries/latest', $h)->assertOk()->assertExactJson(['battleId' => $entry, 'day' => 23, 'total' => 10]);

    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['closesAt'] + 3600_000));
    $rv = $this->getJson("/v1/entries/{$entry}/reveal/0", $h)->assertOk();
    expect($rv->json('correctIndex'))->toBe(right($entry, 0))->and($rv->json('yourChoice'))->toBe(right($entry, 0))
        ->and(array_sum(array_column($rv->json('options'), 'pct')))->toBe(100);
});

it('validates ids and bodies like the zod schemas', function () {
    $h = player();
    $this->postJson('/v1/entries', ['league' => 'cooking'], $h)->assertStatus(400);
    $this->getJson('/v1/entries/not-a-uuid/current', $h)->assertStatus(400)->assertJsonPath('error.details.0.field', 'id');
    $entry = $this->postJson('/v1/entries', [], $h)->json('battleId');
    $this->postJson("/v1/entries/{$entry}/answers", ['index' => 0], $h)->assertStatus(400); // choice must be present
    $this->postJson("/v1/entries/{$entry}/answers", ['index' => '0', 'choice' => 1], $h)->assertStatus(400); // strict number
    $this->postJson("/v1/entries/{$entry}/answers", ['index' => 0, 'choice' => 4], $h)->assertStatus(400);
    $this->postJson("/v1/entries/{$entry}/answers", ['index' => 0, 'choice' => null], $h)->assertOk()->assertJsonPath('correct', false);
    $this->getJson("/v1/entries/{$entry}/reveal/20", $h)->assertStatus(400);
    $this->getJson('/v1/entries/latest', $h)->assertStatus(404)->assertJsonPath('error.message', 'Finished battle not found.');
});

it("hides other players' entries", function () {
    $entry = $this->postJson('/v1/entries', [], player())->json('battleId');
    $this->postJson("/v1/entries/{$entry}/answers", ['index' => 0, 'choice' => 0], player('ada', 'EN'))
        ->assertStatus(404)->assertJsonPath('error.message', 'Battle entry not found.');
});

it('never scores a lost race twice (transaction rolls back)', function () {
    $entry = $this->postJson('/v1/entries', [], player())->json('battleId');
    $repo = app(EntriesRepository::class);
    $e = $repo->findById($entry);
    $a = new EntryAnswerRow($entry, 0, $this->seed['questionIds'][0], 1, true, 150, $e->currentSentAt, $e->currentSentAt + 1000);
    expect($repo->recordAnswer($a))->toBeTrue()
        ->and($repo->recordAnswer($a))->toBeFalse();               // same key: insert ignored
    DB::table('entry_answers')->where('entry_id', $entry)->delete();  // answer row gone, but entry already moved on
    expect($repo->recordAnswer($a))->toBeFalse();                    // update matches 0 rows → insert rolled back
    expect(DB::table('entry_answers')->where('entry_id', $entry)->count())->toBe(0);
    expect($repo->findById($entry))->score->toBe(1)->points->toBe(150)->currentIndex->toBe(1);
});

it('close job settles abandoned entries, writes state_results once, and is idempotent', function () {
    $this->postJson('/v1/entries', [], player())->assertStatus(201); // starts, then disappears
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['closesAt'] + 3600_000));
    $svc = app(BattlesService::class);
    expect(array_map(fn ($b) => $b->id, $svc->dueForClose()))->toBe([$this->battleId]);
    expect($svc->closeBattle($this->battleId))->toBe(['settled' => 1])
        ->and($svc->closeBattle($this->battleId))->toBe(['settled' => 0]);
    expect(DB::table('battles')->where('id', $this->battleId)->value('status'))->toBe('finalized');
    $kw = DB::table('state_results')->where('battle_id', $this->battleId)->where('state_code', 'KW')->first();
    expect($kw->players)->toBe(1)->and((float) $kw->average)->toBe(0.0)->and($kw->rank)->toBe(1);
    expect($svc->dueForClose())->toBe([]);
});

it('reads a finished result without changing it, for player-finished and auto-finished entries', function () {
    $h = player();
    $entry = $this->postJson('/v1/entries', [], $h)->json('battleId');
    $this->getJson("/v1/entries/{$entry}/result", $h)->assertStatus(409)
        ->assertExactJson(['error' => ['code' => 'not_finished', 'message' => "This battle isn't finished yet."]]);
    expect(DB::table('entries')->where('id', $entry)->value('finished_at'))->toBeNull(); // reading never finishes it
    $this->getJson('/v1/entries/not-a-uuid/result', $h)->assertStatus(400);

    $abandoned = player('ada_1', 'LA');
    $left = $this->postJson('/v1/entries', [], $abandoned)->json('battleId');
    $this->postJson("/v1/entries/{$left}/answers", ['index' => 0, 'choice' => right($left, 0)], $abandoned)->assertOk();

    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['closesAt'] + 3600_000));
    $finished = $this->postJson("/v1/entries/{$entry}/finish", [], $h)->assertOk()->json();
    $this->getJson("/v1/entries/{$entry}/result", $h)->assertOk()->assertExactJson($finished);
    expect($finished['stateRank'])->toBe(1);

    expect(app(BattlesService::class)->closeBattle($this->battleId))->toBe(['settled' => 1]); // the server finishes ada's

    // the player's own numbers are fixed; the state's position is the table as it stands now (Lagos went ahead)
    $later = $this->getJson("/v1/entries/{$entry}/result", $h)->assertOk()->json();
    $own = ['day', 'score', 'total', 'answers', 'points', 'stateCode'];
    expect(array_intersect_key($later, array_flip($own)))->toBe(array_intersect_key($finished, array_flip($own)))
        ->and($later['stateRank'])->toBe(2);
    $auto =$this->getJson("/v1/entries/{$left}/result", $abandoned)->assertOk()->json();
    expect(array_keys($auto))->toBe(array_keys($finished))
        ->and($auto['score'])->toBe(1)->and($auto['answers'])->toBe([true, false, false, false, false, false, false, false, false, false]);
    $this->postJson("/v1/entries/{$left}/finish", [], $abandoned)->assertOk()->assertExactJson($auto); // finish stays a safe read too
    $this->getJson("/v1/entries/{$left}/result", $h)->assertStatus(404); // someone else's entry
});

it('keeps a result complete after the live table has expired', function () {
    $h = player();
    $entry = $this->postJson('/v1/entries', [], $h)->json('battleId');
    $rival = player('ada_1', 'LA');
    $theirs = $this->postJson('/v1/entries', [], $rival)->json('battleId');
    $this->postJson("/v1/entries/{$theirs}/answers", ['index' => 0, 'choice' => right($theirs, 0)], $rival)->assertOk();
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['closesAt'] + 3600_000));
    app(BattlesService::class)->closeBattle($this->battleId);
    $fresh = $this->getJson("/v1/entries/{$entry}/result", $h)->assertOk()->json();
    expect($fresh['stateRank'])->toBe(2)->and($fresh['percentile'])->toBe(100)->and($fresh['banter'])->toContain('Lagos');

    // a week later Redis has dropped lb:{battle}:* (and every cache): the result comes from state_results and entries
    Redis::connection()->flushdb();
    app(App\Core\Cache\Cache::class)->flushLocal();
    expect(Redis::connection()->exists("lb:{$this->battleId}:cnt"))->toBe(0);
    Carbon::setTestNow(Carbon::now()->addDays(30));
    $this->getJson("/v1/entries/{$entry}/result", $h)->assertOk()->assertExactJson(['streak' => 0] + $fresh);
    $top = $this->getJson("/v1/entries/{$theirs}/result", $rival)->assertOk()->json();
    expect($top['stateRank'])->toBe(1)->and($top['percentile'])->toBe(50)->and($top['score'])->toBe(1);
});

it('tops up time on resume from the pause budget', function () {
    $h = player();
    $entry = $this->postJson('/v1/entries', [], $h)->json('battleId');
    Carbon::setTestNow(Carbon::now()->addSeconds(78));
    $this->getJson("/v1/entries/{$entry}/current", $h)->assertOk()->assertJsonPath('remainingMs', 10_000);
    Carbon::setTestNow(Carbon::now()->addSeconds(10));
    $this->getJson("/v1/entries/{$entry}/current", $h)->assertOk()->assertJsonPath('remainingMs', 2_000);
    expect(DB::table('entries')->where('id', $entry)->value('pause_used_ms'))->toBe(30_000);
});
