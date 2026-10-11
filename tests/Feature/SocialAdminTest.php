<?php

use App\Core\WatTime;
use App\Modules\Auth\Repositories\Contracts\SessionsRepository;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Derbies, challenges, stats and the admin question bank, on PostgreSQL + Redis.

beforeEach(function () {
    $now = Carbon::parse('2026-10-23T12:00:00+01:00'); // Friday: Mon..Thu derbies are in the past
    Carbon::setTestNow($now);
    $this->seed = DatabaseSeeder::seed(20, 15, '2026-10-01', $now->getTimestampMs());
    $this->times = WatTime::battleTimes('2026-10-23', 20, 15);
});

function guest(string $name, string $state = 'KW'): array
{
    return ['Authorization' => 'Bearer '.test()->postJson('/v1/auth/guest', ['username' => $name, 'stateCode' => $state])->json('token')];
}

function asAdmin(string $username): array
{
    $id = DB::table('users')->where('username', $username)->value('id');

    return ['Authorization' => 'Bearer '.app(SessionsRepository::class)->create($id)];
}

/** Plays and finishes today's battle; returns the entry id. */
function playBattle(array $h, int $correct): string
{
    $times = WatTime::battleTimes('2026-10-23', 20, 15);
    Carbon::setTestNow(Carbon::createFromTimestampMs($times['opensAt'] + 1000));
    $e = test()->postJson('/v1/entries', [], $h)->json('battleId');
    for ($i = 0; $i < 10; $i++) {
        $choice = $i < $correct ? right($e, $i) : (right($e, $i) + 1) % 4;
        test()->postJson("/v1/entries/{$e}/answers", ['index' => $i, 'choice' => $choice], $h)->assertOk();
    }
    test()->postJson("/v1/entries/{$e}/finish", [], $h)->assertOk();

    return $e;
}

it('shows tonight\'s derby, the week and the weekly rival', function () {
    $p = $this->getJson('/v1/derbies', guest('tobi_codes', 'KW'))->assertOk()->json();
    expect(array_keys($p))->toBe(['tonight', 'week', 'rival']);
    expect($p['tonight'])->toMatchArray(['home' => 'Oyo', 'away' => 'Osun', 'region' => 'South West', 'headToHead' => 'First meeting.']);
    expect($p['week'])->toHaveCount(7)
        ->and($p['week'][0])->toBe(['day' => 'Mon', 'home' => 'Enugu', 'away' => 'Anambra', 'status' => 'done', 'result' => 'No result'])
        ->and($p['week'][4])->toBe(['day' => 'Fri', 'home' => 'Oyo', 'away' => 'Osun', 'status' => 'upcoming']);
    // Thursday was seeded as a finalized derby night (LA v OG) with results for every state
    expect($p['week'][3]['status'])->toBe('done')->and($p['week'][3]['result'])->toMatch('/^(Lagos|Ogun) won \d+\.\d–\d+\.\d$/');
    expect($p['rival'])->toHaveKeys(['vs', 'lead', 'dayOfWeek'])->and($p['rival']['dayOfWeek'])->toBe(5);
});

it('runs a friend challenge without touching the state table', function () {
    $tobi = guest('tobi_codes', 'KW');
    $e = playBattle($tobi, 7);
    $table = DB::table('state_results')->count();
    $live = Illuminate\Support\Facades\Redis::connection()->hgetall('lb:'.DB::table('entries')->where('id', $e)->value('battle_id').':cnt');

    $c = $this->postJson('/v1/challenges', ['entryId' => $e], $tobi)->assertStatus(201)->json();
    expect($c['url'])->toBe("http://localhost:5173/?challenge={$c['id']}");
    $this->postJson("/v1/challenges/{$c['id']}/accept", [], $tobi)->assertStatus(400)
        ->assertJsonPath('error.message', "You can't accept your own challenge.");

    $ada = guest('ada_dev', 'EN');
    $this->postJson("/v1/challenges/{$c['id']}/accept", [], $ada)->assertStatus(204);
    $this->postJson("/v1/challenges/{$c['id']}/accept", [], $ada)->assertStatus(204); // idempotent for the same friend
    $this->postJson("/v1/challenges/{$c['id']}/accept", [], guest('bola', 'LA'))->assertStatus(409)->assertJsonPath('error.code', 'challenge_taken');

    $this->getJson('/v1/challenges', $tobi)->assertOk()
        ->assertExactJson([['id' => $c['id'], 'username' => 'ada_dev', 'state' => 'Enugu', 'status' => 'waiting']]);

    playBattle($ada, 7);
    $this->getJson('/v1/challenges', $tobi)->assertJsonPath('0.status', 'draw')->assertJsonPath('0.score', '7–7');
    $this->getJson('/v1/challenges', $ada)->assertJsonPath('0.username', 'tobi_codes')->assertJsonPath('0.state', 'Kwara');

    // only the two players' own finishes moved the table, never the challenge
    expect(DB::table('state_results')->count())->toBe($table);
    expect(count($live))->toBe(1);
});

it('refuses challenges on unfinished or foreign entries', function () {
    $tobi = guest('tobi_codes');
    Carbon::setTestNow(Carbon::createFromTimestampMs($this->times['opensAt'] + 1000));
    $e = $this->postJson('/v1/entries', [], $tobi)->json('battleId');
    $this->postJson('/v1/challenges', ['entryId' => $e], $tobi)->assertStatus(409)->assertJsonPath('error.code', 'not_finished');
    $this->postJson('/v1/challenges', ['entryId' => $e], guest('ada'))->assertStatus(404);
    $this->postJson('/v1/challenges', ['entryId' => 'nope'], $tobi)->assertStatus(400);
    $this->postJson('/v1/challenges/'.$e.'/accept', [], $tobi)->assertStatus(404)->assertJsonPath('error.message', 'Challenge not found.');
});

it('builds the stats page with badges', function () {
    $h = guest('tobi_codes');
    playBattle($h, 10);
    $s = $this->getJson('/v1/stats', $h)->assertOk()->json();
    expect(array_keys($s))->toBe(['player', 'history', 'accuracy', 'badges', 'placesPushed'])
        ->and($s['history'])->toBe([10])
        ->and($s['player']['battles'])->toBe(1)->and($s['player']['average'])->toBe(10)
        ->and($s['badges'])->toContain(['name' => 'Perfect 10', 'earned' => true], ['name' => 'First battle', 'earned' => true], ['name' => 'Week Warrior', 'earned' => false])
        ->and($s['placesPushed'])->toBe(0);
    expect($s['accuracy'])->toBe([]); // the seeded battle has at most 2 questions per subject; accuracy needs 3+
});

it('keeps the admin area for admins and needs a second person to approve', function () {
    $this->getJson('/v1/admin/questions', guest('tobi_codes'))->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $editor = asAdmin('sw_editor');
    $admin = asAdmin('sw_admin');

    $body = ['subject' => 'maths', 'text' => '  What is 2 + 2 in base ten?  ', 'options' => ['3', '4', '5', '22'], 'answer' => 1, 'explanation' => 'Two and two make four.'];
    $q = $this->postJson('/v1/admin/questions', $body, $editor)->assertStatus(201)->json();
    expect($q['status'])->toBe('draft')->and($q['text'])->toBe('What is 2 + 2 in base ten?')->and($q['options'])->toBe(['3', '4', '5', '22'])
        ->and(array_keys($q))->toBe(['id', 'subject', 'text', 'highlight', 'options', 'answer', 'explanation', 'fun_headline', 'fun_banter', 'status', 'author_id', 'approved_by', 'created_at'])
        ->and($q['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');

    $this->postJson("/v1/admin/questions/{$q['id']}/approve", [], $editor)->assertStatus(403)
        ->assertJsonPath('error.message', 'Someone other than the author must approve this question.');
    $this->postJson("/v1/admin/questions/{$q['id']}/approve", [], $admin)->assertOk()->assertJsonPath('status', 'approved');
    $this->postJson("/v1/admin/questions/{$q['id']}/approve", [], $admin)->assertStatus(409)->assertJsonPath('error.code', 'not_draft');

    $this->postJson('/v1/admin/questions', [...$body, 'options' => ['a', 'A ', 'b', 'c']], $editor)->assertStatus(400)
        ->assertJsonPath('error.message', 'The 4 options must all be different.');
    $this->postJson('/v1/admin/questions', [...$body, 'subject' => 'cooking'], $editor)->assertStatus(400)
        ->assertJsonPath('error.message', 'Unknown subject "cooking".');
    $this->postJson('/v1/admin/questions', [...$body, 'highlight' => 'seven'], $editor)->assertStatus(400)
        ->assertJsonPath('error.message', 'The highlighted word must appear in the question text.');
    $this->postJson('/v1/admin/questions', [...$body, 'options' => ['a', 'b', 'c']], $editor)->assertStatus(400);
    $this->postJson('/v1/admin/questions', [...$body, 'text' => 'short'], $editor)->assertStatus(400);

    $list = $this->getJson('/v1/admin/questions?status=draft&limit=5', $admin)->assertOk()->json();
    expect($list)->toBe([]);
    $this->getJson('/v1/admin/questions?limit=0', $admin)->assertStatus(400);
    expect($this->getJson('/v1/admin/questions?subject=maths', $admin)->json())->toHaveCount(3);
});

it('lets admins schedule battles and prepare days', function () {
    $admin = asAdmin('sw_admin');
    $b = $this->postJson('/v1/admin/battles', ['date' => '2026-10-30', 'kind' => 'daily', 'questionIds' => $this->seed['questionIds']], $admin)
        ->assertStatus(201)->json();
    expect(array_keys($b))->toBe(['id', 'date', 'kind', 'subject', 'dayNumber', 'opensAt', 'closesAt', 'questionIds', 'uprisingStates', 'status'])
        ->and($b['dayNumber'])->toBe(30)->and($b['questionIds'])->toBe($this->seed['questionIds'])->and($b['uprisingStates'])->toBe([]);
    $this->postJson('/v1/admin/battles', ['date' => '30-10-2026', 'kind' => 'daily'], $admin)->assertStatus(400);
    $r = $this->postJson('/v1/admin/days/2026-10-31/prepare', [], $admin)->assertOk()->json();
    expect($r['created'])->toBe([])->and($r['skipped'])->toHaveCount(7);
    $this->postJson('/v1/admin/days/tomorrow/prepare', [], $admin)->assertStatus(400);
});
