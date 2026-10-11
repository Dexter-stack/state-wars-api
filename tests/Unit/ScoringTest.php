<?php

// Port of the Node test/unit/scoring.test.ts (12 cases).

use App\Core\WatTime;
use App\Modules\Battles\Support\Scoring;
use App\Modules\Users\Support\Ranks;

$base = ['answer' => 2, 'limitMs' => 60_000, 'graceMs' => 2_500];
$score = fn (?int $choice, int $elapsedMs) => Scoring::scoreAnswer($choice, $base['answer'], $elapsedMs, $base['limitMs'], $base['graceMs']);

describe('scoreAnswer', function () use ($score) {
    it('gives full speed bonus for an instant right answer', function () use ($score) {
        expect($score(2, 0))->toBe(['inTime' => true, 'correct' => true, 'points' => 150]);
    });
    it('gives base points only at the time limit', function () use ($score) {
        expect($score(2, 60_000)['points'])->toBe(100);
    });
    it('accepts answers inside the network grace period', function () use ($score) {
        expect($score(2, 62_000)['correct'])->toBeTrue();
    });
    it('rejects answers after limit + grace, even if right', function () use ($score) {
        expect($score(2, 63_000))->toMatchArray(['inTime' => false, 'correct' => false, 'points' => 0]);
    });
    it('scores no answer and wrong answers as zero', function () use ($score) {
        expect($score(null, 10)['points'])->toBe(0)
            ->and($score(1, 10)['points'])->toBe(0);
    });
});

describe('per-player shuffles', function () {
    it('are deterministic for a seed and are true permutations', function () {
        expect(Scoring::questionOrder(42, 10))->toBe(Scoring::questionOrder(42, 10));
        $q = Scoring::questionOrder(42, 10);
        sort($q);
        expect($q)->toBe(range(0, 9));
        $o = Scoring::optionOrder(42, 3);
        sort($o);
        expect($o)->toBe([0, 1, 2, 3]);
    });
    it('differ between players', function () {
        $seen = [];
        for ($s = 0; $s < 50; $s++) {
            $seen[implode('', Scoring::optionOrder($s * 7 + 1, 0))] = true;
        }
        expect(count($seen))->toBeGreaterThan(10);
    });
    it('map display and canonical options both ways', function () {
        $order = Scoring::optionOrder(99, 5);
        for ($d = 0; $d < 4; $d++) {
            expect(Scoring::toDisplay($order, Scoring::toCanonical($order, $d)))->toBe($d);
        }
    });
});

describe('WAT time helpers', function () {
    $ms = fn (string $iso) => (int) (new DateTimeImmutable($iso))->format('Uv');

    it('uses the West Africa calendar day', function () use ($ms) {
        expect(WatTime::watDate($ms('2026-10-10T22:59:00Z')))->toBe('2026-10-10')
            ->and(WatTime::watDate($ms('2026-10-10T23:00:00Z')))->toBe('2026-10-11');
    });
    it('opens battles at 8pm WAT (19:00 UTC)', function () use ($ms) {
        expect(WatTime::battleTimes('2026-10-10', 20, 15)['opensAt'])->toBe($ms('2026-10-10T19:00:00.000Z'));
    });
    it('counts days and weekdays', function () use ($ms) {
        expect(WatTime::addDays('2026-10-31', 1))->toBe('2026-11-01')
            ->and(WatTime::dayNumber('2026-10-01', '2026-10-01'))->toBe(1)
            ->and(WatTime::watWeekday($ms('2026-10-09T12:00:00Z')))->toBe(5); // Friday
    });
});

describe('ranks', function () {
    it('climbs the ladder by points', function () {
        expect(Ranks::rankFor(0))->toBe(['rank' => 'Olodo', 'pointsToNext' => 2000])
            ->and(Ranks::rankFor(25_000)['rank'])->toBe('Efiko')
            ->and(Ranks::rankFor(1_000_000))->toBe(['rank' => 'Professor', 'pointsToNext' => 0]);
    });
});
