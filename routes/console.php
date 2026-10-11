<?php

use App\Core\Clock\Clock;
use App\Core\WatTime;
use App\Modules\Battles\Jobs\PrepareDayJob;
use App\Modules\Battles\Jobs\SweepJob;
use App\Modules\Battles\Services\BattlesService;
use App\Modules\Battles\Services\SchedulerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;

// ---------- schedule (run `php artisan schedule:work`; onOneServer makes extra schedulers harmless) ----------
//   prepare-day  00:05 WAT  create today's battles (daily + leagues), Uprising states on Fridays
//   sweep        every min  close battles whose last player has finished: settle entries, finalize table

Schedule::job(new PrepareDayJob)->name('prepare-day')
    ->dailyAt('00:05')->timezone('Africa/Lagos')
    ->withoutOverlapping()->onOneServer();

Schedule::job(new SweepJob)->name('sweep')
    ->everyMinute()
    ->withoutOverlapping()->onOneServer();

// ---------- commands ----------

Artisan::command('statewars:prepare-day {date? : YYYY-MM-DD, defaults to today in WAT}', function (SchedulerService $scheduler, Clock $clock) {
    $date = $this->argument('date') ?? WatTime::watDate($clock->nowMs());
    $r = $scheduler->prepareDay($date);
    $this->info("{$date}: created ".json_encode($r['created']));
    foreach ($r['skipped'] as $s) {
        $this->line("  skipped {$s}");
    }
})->purpose("Create a day's battles now (the scheduler also does this at 00:05 WAT)");

Artisan::command('statewars:sweep', function (BattlesService $battles) {
    $due = $battles->dueForClose();
    foreach ($due as $b) {
        $r = $battles->closeBattle($b->id);
        $this->info("closed {$b->id} ({$b->kind}".($b->subject ? " {$b->subject}" : '').'): settled '.$r['settled']);
    }
    $this->line(count($due).' battle(s) closed');
})->purpose('Close battles whose last player has finished (the scheduler does this every minute)');

Artisan::command('statewars:verify-battle {battle? : battle id, defaults to today\'s daily}', function (Clock $clock) {
    $id = $this->argument('battle')
        ?? DB::table('battles')->where('battle_date', WatTime::watDate($clock->nowMs()))->where('kind', 'daily')->value('id');
    if (! $id) {
        $this->error('No battle found.');

        return 1;
    }
    $answers = DB::selectOne(
        'SELECT count(*) AS n, count(DISTINCT (a.entry_id, a.q_index)) AS d FROM entry_answers a JOIN entries e ON e.id = a.entry_id WHERE e.battle_id = ?',
        [$id],
    );
    $this->line("battle {$id}");
    $this->line("entry_answers: {$answers->n} rows, {$answers->d} distinct (entry_id, q_index)");

    $uprising = json_decode(DB::table('battles')->where('id', $id)->value(DB::raw('array_to_json(uprising_states)')), true);
    $multiplier = (float) config('statewars.uprising_multiplier');
    $db = DB::select(
        'SELECT state_code, count(*) AS n, sum(score) AS s FROM entries WHERE battle_id = ? AND finished_at IS NOT NULL GROUP BY state_code ORDER BY state_code',
        [$id],
    );
    $cnt = Redis::connection()->hgetall("lb:{$id}:cnt") ?: [];
    $sum = Redis::connection()->hgetall("lb:{$id}:sum") ?: [];
    $bad = 0;
    $rows = [];
    foreach ($db as $r) {
        $weighted = $r->s * (in_array($r->state_code, $uprising, true) ? $multiplier : 1);
        $ok = (int) ($cnt[$r->state_code] ?? 0) === (int) $r->n && abs((float) ($sum[$r->state_code] ?? 0) - $weighted) < 1e-9;
        $bad += $ok ? 0 : 1;
        $rows[] = [$r->state_code, $r->n, $cnt[$r->state_code] ?? 0, $weighted, $sum[$r->state_code] ?? 0, $ok ? 'ok' : 'MISMATCH'];
    }
    $extra = array_diff(array_keys($cnt), array_column($db, 'state_code'));
    $this->table(['state', 'db players', 'redis players', 'db weighted sum', 'redis sum', ''], $rows);
    if ((int) $answers->n !== (int) $answers->d || $bad > 0 || $extra !== []) {
        $this->error("FAIL: {$bad} state(s) differ".($extra ? ', Redis has extra states: '.implode(',', $extra) : ''));

        return 1;
    }
    $this->info('OK: no duplicate answers; Redis totals equal database totals for every state.');

    return 0;
})->purpose('Check a battle: no duplicate answers, Redis live totals equal Postgres totals');
