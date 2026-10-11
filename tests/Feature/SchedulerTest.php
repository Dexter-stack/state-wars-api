<?php

use App\Core\Db\Pg;
use App\Modules\Battles\Services\SchedulerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $now = Carbon::parse('2026-10-22T12:00:00+01:00');
    Carbon::setTestNow($now);
    DatabaseSeeder::seed(20, 15, '2026-10-01', $now->getTimestampMs());
});

function approvedQuestions(string $subject, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        DB::insert(
            'INSERT INTO questions (subject, text, options, answer, explanation, status) VALUES (?, ?, '.Pg::arrayParam('text').", 0, 'because', 'approved')",
            [$subject, "{$subject} question {$i}", Pg::json(['a', 'b', 'c', 'd'])],
        );
    }
}

it('prepares the daily battle and every league with enough fresh questions', function () {
    approvedQuestions('english', 20);
    $r = app(SchedulerService::class)->prepareDay('2026-10-24');
    expect($r['created'])->toBe(['daily', 'english'])
        ->and($r['skipped'])->toContain('maths: Only 0 fresh approved questions for maths. Approve more first.');

    $daily = DB::table('battles')->where('battle_date', '2026-10-24')->where('kind', 'daily')->first();
    expect($daily->day_number)->toBe(24);
    expect(Pg::ms($daily->opens_at))->toBe((int) Carbon::parse('2026-10-24T20:00:00+01:00')->getTimestampMs());

    // idempotent: the existing slots are returned, nothing new is created
    expect(app(SchedulerService::class)->prepareDay('2026-10-24')['created'])->toBe(['daily', 'english']);
    expect(DB::table('battles')->where('battle_date', '2026-10-24')->count())->toBe(2);
});

it('never reuses a question within 60 days', function () {
    approvedQuestions('maths', 10);
    $svc = app(SchedulerService::class);
    $svc->schedule('2026-10-24', 'league', 'maths');
    expect(fn () => $svc->schedule('2026-10-25', 'league', 'maths'))->toThrow(App\Core\AppException::class, 'Only 0 fresh approved questions for maths');
});

it('gives the bottom states the Uprising boost on Fridays', function () {
    // Thursday 22nd is the seeded "today" battle: give every state a live total, best first in catalog order.
    approvedQuestions('english', 10);
    $svc = app(SchedulerService::class);
    $thursday = DB::table('battles')->where('battle_date', '2026-10-22')->value('id');
    $redis = Illuminate\Support\Facades\Redis::connection();
    foreach (App\Modules\Catalog\Catalog::STATES as $i => $s) {
        $redis->hincrbyfloat("lb:{$thursday}:sum", $s['code'], 37 - $i);
        $redis->hincrby("lb:{$thursday}:cnt", $s['code'], 1);
    }
    $friday = $svc->schedule('2026-10-23', 'daily', null);
    expect($friday->uprisingStates)->toHaveCount(10)
        ->and($friday->uprisingStates)->toBe(array_column(array_slice(App\Modules\Catalog\Catalog::STATES, -10), 'code'));
});

it('validates admin scheduling input', function () {
    $svc = app(SchedulerService::class);
    expect(fn () => $svc->schedule('2026-10-24', 'league', 'cooking'))->toThrow(App\Core\AppException::class, 'A league battle needs a valid subject.');
    expect(fn () => $svc->schedule('2026-10-24', 'daily', 'maths'))->toThrow(App\Core\AppException::class, "The daily battle mixes subjects; don't set one.");
    expect(fn () => $svc->schedule('2026-10-24', 'daily', null, ['00000000-0000-0000-0000-000000000000']))
        ->toThrow(App\Core\AppException::class, 'Every question must exist and be approved.');
});
