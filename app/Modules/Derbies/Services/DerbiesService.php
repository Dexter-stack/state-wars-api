<?php

namespace App\Modules\Derbies\Services;

use App\Core\Clock\Clock;
use App\Core\JsMath;
use App\Core\WatTime;
use App\Modules\Catalog\Catalog;
use App\Modules\Derbies\Repositories\Contracts\DerbiesRepository;
use App\Modules\Leaderboard\Repositories\Contracts\ResultsRepository;
use App\Modules\Leaderboard\Services\LeaderboardService;
use App\Modules\Users\Services\UsersService;
use App\Shared\Contracts\DerbyPage;

/** Tonight's derby, the week's derbies, the weekly rival. Port of derbies.service.ts. */
final class DerbiesService
{
    private const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(
        private readonly DerbiesRepository $derbies,
        private readonly ResultsRepository $results,
        private readonly LeaderboardService $leaderboard,
        private readonly UsersService $users,
        private readonly Clock $clock,
    ) {}

    public function page(string $userId): DerbyPage
    {
        $now = $this->clock->nowMs();
        $today = WatTime::watDate($now);
        $weekday = WatTime::watWeekday($now);
        $monday = WatTime::watDate($now - ($weekday - 1) * WatTime::DAY_MS);
        $sunday = WatTime::addDays($monday, 6);
        $user = $this->users->get($userId);

        $tonight = $this->derbies->forDate($today);
        $week = $this->derbies->between($monday, $sunday);
        $weekTable = $this->leaderboard->periodTable('daily', null, 'week', $monday);

        $tonightOut = null;
        if ($tonight) {
            $avg = function (string $code) use ($weekTable) {
                foreach ($weekTable as $r) {
                    if ($r['stateCode'] === $code) {
                        return $r['average'];
                    }
                }

                return 0;
            };
            $h2h = $this->results->headToHead($tonight['home'], $tonight['away'], $today, 20);
            $homeWins = count(array_filter($h2h, fn ($r) => $r['home'] > $r['away']));
            $awayWins = count(array_filter($h2h, fn ($r) => $r['away'] > $r['home']));
            $leader = $homeWins === $awayWins ? null : ($homeWins > $awayWins ? $tonight['home'] : $tonight['away']);
            $tonightOut = [
                'home' => Catalog::stateName($tonight['home']),
                'away' => Catalog::stateName($tonight['away']),
                'region' => $tonight['region'] ?: Catalog::stateRegion($tonight['home']),
                'homeAvg' => $avg($tonight['home']),
                'awayAvg' => $avg($tonight['away']),
                'headToHead' => $h2h === [] ? 'First meeting.' : ($leader
                    ? Catalog::stateName($leader).' leads the head-to-head '.max($homeWins, $awayWins).' wins to '.min($homeWins, $awayWins).'.'
                    : "Level at {$homeWins} wins each."),
            ];
        }

        $weekOut = array_map(function (array $d) use ($today) {
            $noon = WatTime::watMidnight($d['date']) + 12 * WatTime::HOUR_MS;
            $base = ['day' => self::DAY_NAMES[WatTime::watWeekday($noon) - 1], 'home' => Catalog::stateName($d['home']), 'away' => Catalog::stateName($d['away'])];
            if ($d['date'] >= $today) {
                return $base + ['status' => 'upcoming'];
            }
            $r = null;
            foreach ($this->results->headToHead($d['home'], $d['away'], WatTime::addDays($d['date'], 1), 1) as $x) {
                if ($x['date'] === $d['date']) {
                    $r = $x;
                    break;
                }
            }
            if (! $r) {
                return $base + ['status' => 'done', 'result' => 'No result'];
            }
            $winner = $r['home'] >= $r['away'] ? $d['home'] : $d['away'];

            return $base + ['status' => 'done', 'result' => Catalog::stateName($winner).' won '
                .JsMath::toFixed(max($r['home'], $r['away']), 1).'–'.JsMath::toFixed(min($r['home'], $r['away']), 1)];
        }, $week);

        $ranked = array_values(array_filter($weekTable, fn ($r) => $r['ranked']));
        $i = array_search($user->stateCode, array_column($ranked, 'stateCode'), true);
        $rival = null;
        if ($i !== false && count($ranked) > 1) {
            $other = $ranked[$i === 0 ? 1 : $i - 1];
            $rival = ['vs' => $other['state'], 'lead' => JsMath::round1($ranked[$i]['average'] - $other['average']), 'dayOfWeek' => $weekday];
        }

        return new DerbyPage($tonightOut, $weekOut, $rival);
    }
}
