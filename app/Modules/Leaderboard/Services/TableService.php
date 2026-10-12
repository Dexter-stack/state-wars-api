<?php

namespace App\Modules\Leaderboard\Services;

use App\Core\Clock\Clock;
use App\Core\WatTime;
use App\Modules\Catalog\Catalog;
use App\Modules\Today\Services\BattleLocator;

/**
 * GET /v1/table: public and identical for everyone (the phone highlights the player's own state),
 * so it is cached (two levels, single-flight) and carries Cache-Control for a CDN.
 */
final class TableService
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly BattleLocator $locator,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{rows: list<array>, maxAge: int} rows in the response field order
     */
    public function table(string $period, string $subject): array
    {
        $kind = $subject === 'all' ? 'daily' : 'league';
        $subj = $subject === 'all' ? null : $subject;

        $boosted = [];
        if ($period === 'tonight') {
            $b = $this->locator->todaysBattle($kind, $subj);
            $boosted = $b ? $b->uprisingStates : [];
            $rows = $b ? $this->leaderboard->liveTable($b) : array_map(fn ($s) => [
                'stateCode' => $s['code'], 'state' => $s['name'], 'players' => 0, 'average' => 0, 'move' => 0, 'ranked' => false, 'rank' => null,
            ], Catalog::STATES);
            $maxAge = 5;
        } else {
            $now = $this->clock->nowMs();
            $monday = WatTime::watDate($now - (WatTime::watWeekday($now) - 1) * WatTime::DAY_MS);
            $rows = $this->leaderboard->periodTable($kind, $subj, $period, $period === 'week' ? $monday : null);
            $maxAge = 60;
        }

        return [
            'rows' => array_map(fn ($r) => [
                'state' => $r['state'], 'stateCode' => $r['stateCode'], 'players' => $r['players'], 'move' => $r['move'],
                'average' => $r['average'], 'ranked' => $r['ranked'], 'rank' => $r['rank'],
                // scores double in tonight's battle (Uprising); always false on week and all-time tables
                'boosted' => in_array($r['stateCode'], $boosted, true),
            ], $rows),
            'maxAge' => $maxAge,
        ];
    }
}
