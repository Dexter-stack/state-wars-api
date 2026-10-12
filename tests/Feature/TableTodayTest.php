<?php

use App\Core\WatTime;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;

// Seed on Friday 2026-10-23 (noon WAT): Thursday is a finalized battle with results for all 37 states,
// Friday's derby is OY v OS, today's battle opens at 20:00 WAT.
beforeEach(function () {
    $now = Carbon::parse('2026-10-23T12:00:00+01:00');
    Carbon::setTestNow($now);
    DatabaseSeeder::seed(20, 15, '2026-10-01', $now->getTimestampMs());
    $this->times = WatTime::battleTimes('2026-10-23', 20, 15);
});

function tok(string $name = 'tobi_codes', string $state = 'KW'): array
{
    return ['Authorization' => 'Bearer '.test()->postJson('/v1/auth/guest', ['username' => $name, 'stateCode' => $state])->json('token')];
}

it('serves tonight\'s table to anyone with Cache-Control, all 37 states', function () {
    $r = $this->getJson('/v1/table')->assertOk()->assertHeader('Cache-Control', 'max-age=5, public');
    expect($r->json())->toHaveCount(37)
        ->and($r->json('0'))->toBe(['state' => 'Abia', 'stateCode' => 'AB', 'players' => 0, 'move' => 0, 'average' => 0, 'ranked' => false, 'rank' => null, 'boosted' => false]);
});

it('counts a finished player in tonight\'s table, with moves against last night', function () {
    $h = tok();
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['opensAt'] + 1000));
    $e = $this->postJson('/v1/entries', [], $h)->json('battleId');
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['closesAt'] + 3600_000)); // everyone done: finish allowed
    $this->postJson("/v1/entries/{$e}/finish", [], $h)->assertOk();
    $kw = collect($this->getJson('/v1/table')->json())->firstWhere('stateCode', 'KW');
    expect($kw['players'])->toBe(1)->and($kw['ranked'])->toBeTrue()->and($kw['rank'])->toBe(1);
    // Kwara finished Thursday at some rank r: move = r - 1
    $prev = DB::table('state_results')->join('battles', 'battles.id', '=', 'state_results.battle_id')
        ->where('battles.battle_date', '2026-10-22')->where('state_code', 'KW')->value('rank');
    expect($kw['move'])->toBe($prev - 1);
});

it('serves week and all-time tables from finalized results', function () {
    $week = $this->getJson('/v1/table?period=week')->assertOk()->assertHeader('Cache-Control', 'max-age=60, public');
    expect($week->json())->toHaveCount(37)->and($week->json('0.rank'))->toBe(1)->and($week->json('0.ranked'))->toBeTrue();
    expect($week->json('0.average'))->toBeGreaterThanOrEqual($week->json('1.average'));
    $this->getJson('/v1/table?period=all&subject=maths')->assertOk()->assertJsonCount(37);
    $this->getJson('/v1/table?period=yesterday')->assertStatus(400);
    $this->getJson('/v1/table?subject=cooking')->assertStatus(400);
});

it('builds the Today screen', function () {
    $h = tok();
    $t = $this->getJson('/v1/today', $h)->assertOk()->json();
    expect(array_keys($t))->toBe(['battleId', 'day', 'status', 'startsAt', 'endsAt', 'myState', 'derby', 'isUprisingDay', 'myEntry']);
    expect($t['status'])->toBe('upcoming')->and($t['day'])->toBe(23)->and($t['isUprisingDay'])->toBeTrue()
        ->and($t['startsAt'])->toBe($this->times['opensAt'])->and($t['endsAt'])->toBe($this->times['closesAt'])
        ->and($t['myState'])->toBe(['rank' => 0, 'move' => 0, 'average' => 0, 'players' => 0])
        ->and($t['derby'])->toBe(['home' => 'Oyo', 'away' => 'Osun', 'note' => 'First derby between these two.'])
        ->and($t['myEntry'])->toBeNull();

    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['opensAt'] + 1));
    expect($this->getJson('/v1/today', $h)->json('status'))->toBe('live');
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['closesAt']));
    $closed = $this->getJson('/v1/today', $h)->json();
    expect($closed['status'])->toBe('closed')->and($closed['startsAt'])->toBe(WatTime::battleTimes('2026-10-24', 20, 15)['opensAt'])
        ->and($closed['day'])->toBe(23)->and($closed['battleId'])->toBe($t['battleId']); // still the battle that just closed
});

it('tells the player about their own entry for today\'s main battle, also after it closes', function () {
    $h = tok();
    $other = tok('ada_1', 'LA');
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['opensAt'] + 1000));
    $e = $this->postJson('/v1/entries', [], $h)->json('battleId');
    expect($this->getJson('/v1/today', $h)->json('myEntry'))->toBe(['entryId' => $e, 'finished' => false]);
    expect($this->getJson('/v1/today', $other)->json('myEntry'))->toBeNull(); // per player

    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['closesAt'] + 3600_000));
    $closed = $this->getJson('/v1/today', $h)->json();
    expect($closed['status'])->toBe('closed')->and($closed['myEntry'])->toBe(['entryId' => $e, 'finished' => false]);
    $this->postJson("/v1/entries/{$e}/finish", [], $h)->assertOk();
    expect($this->getJson('/v1/today', $h)->json('myEntry'))->toBe(['entryId' => $e, 'finished' => true]);

    Carbon::setTestNow(Carbon::parse('2026-10-24T00:00:01+01:00')); // the day rolled over
    $next = $this->getJson('/v1/today', $h)->json();
    expect($next['myEntry'])->toBeNull()->and($next['day'])->toBe(24);
});

it('marks the states that score double tonight, and only on tonight\'s table', function () {
    DB::update("UPDATE battles SET uprising_states = ARRAY['KW','LA'] WHERE battle_date = '2026-10-23' AND kind = 'daily'");
    $boosted = fn (string $url) => collect($this->getJson($url)->assertOk()->json())->where('boosted', true)->pluck('stateCode')->sort()->values()->all();
    expect($boosted('/v1/table'))->toBe(['KW', 'LA'])
        ->and($boosted('/v1/table?period=week'))->toBe([])
        ->and($boosted('/v1/table?period=all'))->toBe([])
        ->and($boosted('/v1/table?subject=maths'))->toBe([]); // no league battle tonight
});

it('lists the six subject leagues', function () {
    $r = $this->getJson('/v1/subjects', tok())->assertOk();
    expect($r->json())->toHaveCount(6)
        ->and($r->json('0'))->toBe(['id' => 'english', 'name' => 'English', 'leader' => 'Not started', 'myStateRank' => 0]);
});
