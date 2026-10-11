<?php

// Port of the Node test/unit/battles.service.test.ts (8 cases): the battle engine against in-memory
// repositories. No framework, no database: proof that services depend only on interfaces.

use App\Core\AppException;
use App\Core\Cache\MemoryCache;
use App\Core\Clock\CarbonClock;
use App\Core\Config\GameConfig;
use App\Core\WatTime;
use App\Modules\Battles\Services\BattlesService;
use App\Modules\Battles\Support\Scoring;
use App\Modules\Leaderboard\Services\LeaderboardService;
use App\Modules\Stats\Services\StatsService;
use App\Modules\Users\Services\UsersService;
use Illuminate\Support\Carbon;
use Tests\Support\Memory\MemBattles;
use Tests\Support\Memory\MemEntries;
use Tests\Support\Memory\MemoryAnswerStatsStore;
use Tests\Support\Memory\MemoryLiveScoreStore;
use Tests\Support\Memory\MemQuestions;
use Tests\Support\Memory\MemResults;
use Tests\Support\Memory\MemStats;
use Tests\Support\Memory\MemUsers;

const DATE = '2026-10-23';

function times(): array
{
    return WatTime::battleTimes(DATE, 20, 15);
}

function clockSet(int $ms): void
{
    Carbon::setTestNow(Carbon::createFromTimestampMs($ms));
}

function clockAdvance(int $ms): void
{
    clockSet((int) Carbon::now()->getTimestampMs() + $ms);
}

function setupBattle(): object
{
    clockSet(times()['opensAt'] + 1000);
    $cfg = new GameConfig(questionTimeLimitSec: 60, answerGraceMs: 2500, pauseBudgetMs: 30_000, minResumeMs: 10_000,
        minPlayersRanked: 1, uprisingMultiplier: 2, uprisingCount: 10, stateChangeDays: 30, devAlwaysOpen: false);
    $clock = new CarbonClock;
    $users = new MemUsers;
    $questions = new MemQuestions;
    $battles = new MemBattles;
    $entries = new MemEntries;
    $results = new MemResults;
    $stats = new MemStats;
    $live = new MemoryLiveScoreStore;
    $cache = new MemoryCache;
    $usersSvc = new UsersService($users, $clock, $cfg);
    $leaderboard = new LeaderboardService($live, $results, $cache, $cfg);
    $statsSvc = new StatsService($stats, $usersSvc, $results, $clock);
    $svc = new BattlesService($battles, $entries, $questions, new MemoryAnswerStatsStore, $leaderboard, $usersSvc, $statsSvc, $cache, $clock, $cfg);

    $qIds = [];
    for ($i = 0; $i < 10; $i++) {
        $qIds[] = $questions->create(['subject' => 'maths', 'text' => "Question {$i}", 'options' => ['a', 'b', 'c', 'd'], 'answer' => $i % 4, 'explanation' => 'x', 'status' => 'approved'])->id;
    }
    $t = times();
    $battle = $battles->create(DATE, 'daily', null, 23, $t['opensAt'], $t['closesAt'], $qIds, []);
    $player = $usersSvc->register('tobi_codes', 'KW', true);

    return (object) compact('svc', 'entries', 'questions', 'battle', 'player', 'live', 'stats', 'users', 'clock', 'cfg');
}

/** The display index of the right answer for this player's $index-th question. */
function rightChoice(object $s, int $seed, int $index): int
{
    $q = $s->questions->rows[$s->battle->questionIds[Scoring::questionOrder($seed, 10)[$index]]];

    return Scoring::toDisplay(Scoring::optionOrder($seed, $index), $q->answer);
}

beforeEach(function () {
    $this->s = setupBattle();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('refuses to start before 8pm', function () {
    clockSet(times()['opensAt'] - 60_000);
    expect(fn () => $this->s->svc->start($this->s->player->id, null))
        ->toThrow(fn (AppException $e) => expect($e->status)->toBe(425));
});

it('never sends the answer with a question', function () {
    $start = $this->s->svc->start($this->s->player->id, null);
    expect(array_keys($start->question->toArray()))->not->toContain('answer');
});

it('scores a perfect run once, even when every answer is sent twice', function () {
    $s = $this->s;
    $battleId = $s->svc->start($s->player->id, null)->battleId;
    $seed = $s->entries->findById($battleId)->seed;
    for ($i = 0; $i < 10; $i++) {
        clockAdvance(5_000);
        $a = $s->svc->answer($s->player->id, $battleId, $i, rightChoice($s, $seed, $i));
        $again = $s->svc->answer($s->player->id, $battleId, $i, (rightChoice($s, $seed, $i) + 1) % 4); // retry with a different choice
        expect($a->correct)->toBeTrue();
        expect($again->toArray())->toEqual($a->toArray()); // the stored answer wins
    }
    $r1 = $s->svc->finish($s->player->id, $battleId);
    $r2 = $s->svc->finish($s->player->id, $battleId);
    expect($r1->score)->toBe(10)->and($r2->score)->toBe(10);
    expect($s->live->totals($s->battle->id)['KW'])->toEqual(['sum' => 10, 'count' => 1]); // counted once
    expect($s->users->rows[$s->player->id]->streak)->toBe(1);
    expect($s->stats->badges($s->player->id))->toContain('perfect_10');
});

it('marks answers after the time limit as wrong', function () {
    $s = $this->s;
    $battleId = $s->svc->start($s->player->id, null)->battleId;
    $seed = $s->entries->findById($battleId)->seed;
    clockAdvance(70_000);
    $a = $s->svc->answer($s->player->id, $battleId, 0, rightChoice($s, $seed, 0));
    expect($a->correct)->toBeFalse()->and($a->points)->toBe(0);
});

it('gives back time after a dropped connection, capped by the pause budget', function () {
    $s = $this->s;
    $battleId = $s->svc->start($s->player->id, null)->battleId;
    clockAdvance(58_000); // 2s left when the connection drops
    clockAdvance(20_000); // back 20s later
    expect($s->svc->resume($s->player->id, $battleId)->remainingMs)->toBe(10_000); // topped up to the minimum
    clockAdvance(10_000);
    expect($s->svc->resume($s->player->id, $battleId)->remainingMs)->toBe(2_000); // 30s budget now used up (28s + 2s): no more top-ups
});

it("rejects playing another player's entry", function () {
    $s = $this->s;
    $battleId = $s->svc->start($s->player->id, null)->battleId;
    $other = (new UsersService($s->users, $s->clock, $s->cfg))->register('ada', 'EN', true);
    expect(fn () => $s->svc->answer($other->id, $battleId, 0, 0))->toThrow(AppException::class);
});

it('keeps answers hidden until everyone has finished', function () {
    $s = $this->s;
    $battleId = $s->svc->start($s->player->id, null)->battleId;
    for ($i = 0; $i < 10; $i++) {
        $s->svc->answer($s->player->id, $battleId, $i, 0);
    }
    $s->svc->finish($s->player->id, $battleId);
    expect(fn () => $s->svc->reveal($s->player->id, $battleId, 0))
        ->toThrow(fn (AppException $e) => expect($e->status)->toBe(425));
    clockSet(times()['closesAt'] + 60 * 60_000);
    $rv = $s->svc->reveal($s->player->id, $battleId, 0);
    expect($rv->yourChoice)->toBe(0)->and($rv->options)->toHaveCount(4);
});

it('close job settles abandoned entries and finalizes the table', function () {
    $s = $this->s;
    $s->svc->start($s->player->id, null); // starts, then disappears
    clockSet(times()['closesAt'] + 60 * 60_000);
    expect(array_map(fn ($b) => $b->id, $s->svc->dueForClose()))->toBe([$s->battle->id]);
    expect($s->svc->closeBattle($s->battle->id))->toBe(['settled' => 1]);
    expect($s->svc->closeBattle($s->battle->id))->toBe(['settled' => 0]); // idempotent
    expect($s->live->totals($s->battle->id)['KW']['count'])->toBe(1);
});
