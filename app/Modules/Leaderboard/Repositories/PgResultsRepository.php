<?php

namespace App\Modules\Leaderboard\Repositories;

use App\Modules\Leaderboard\Repositories\Contracts\ResultsRepository;
use Illuminate\Support\Facades\DB;

final class PgResultsRepository implements ResultsRepository
{
    public function upsert(string $battleId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        DB::table('state_results')->upsert(
            array_map(fn ($r) => [
                'battle_id' => $battleId, 'state_code' => $r['stateCode'], 'players' => $r['players'], 'average' => $r['average'],
                'rank' => $r['rank'], 'move' => $r['move'], 'banter' => $r['banter'],
            ], $rows),
            ['battle_id', 'state_code'],
            ['players', 'average', 'rank', 'move', 'banter'],
        );
    }

    public function previousRanks(string $kind, ?string $subject, string $beforeDate): array
    {
        $rows = DB::select(
            "SELECT r.state_code, r.rank FROM state_results r
             WHERE r.battle_id = (
                 SELECT id FROM battles WHERE kind = ? AND coalesce(subject, '') = ? AND status = 'finalized' AND battle_date < ?
                 ORDER BY battle_date DESC LIMIT 1)
             AND r.rank IS NOT NULL",
            [$kind, $subject ?? '', $beforeDate],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->state_code] = (int) $r->rank;
        }

        return $out;
    }

    public function aggregate(string $kind, ?string $subject, ?string $fromDate): array
    {
        $rows = DB::select(
            "SELECT r.state_code, sum(r.players)::int AS players,
                    round(sum(r.average * r.players) / nullif(sum(r.players), 0), 2)::float AS average
             FROM state_results r JOIN battles b ON b.id = r.battle_id
             WHERE b.kind = ? AND coalesce(b.subject, '') = ? AND b.status = 'finalized'"
                .($fromDate ? ' AND b.battle_date >= ?' : '').'
             GROUP BY r.state_code',
            $fromDate ? [$kind, $subject ?? '', $fromDate] : [$kind, $subject ?? ''],
        );

        return array_map(fn ($r) => [
            'stateCode' => $r->state_code, 'players' => (int) $r->players, 'average' => $r->average === null ? 0 : (float) $r->average,
        ], $rows);
    }

    public function headToHead(string $home, string $away, string $beforeDate, int $limit): array
    {
        $rows = DB::select(
            "SELECT b.battle_date::text AS date, h.average AS home, a.average AS away
             FROM battles b
             JOIN state_results h ON h.battle_id = b.id AND h.state_code = ?
             JOIN state_results a ON a.battle_id = b.id AND a.state_code = ?
             JOIN derbies d ON d.derby_date = b.battle_date
             WHERE b.kind = 'daily' AND b.status = 'finalized' AND b.battle_date < ?
               AND ((d.home = ? AND d.away = ?) OR (d.home = ? AND d.away = ?))
             ORDER BY b.battle_date DESC LIMIT ?",
            [$home, $away, $beforeDate, $home, $away, $away, $home, $limit],
        );

        return array_map(fn ($r) => ['date' => $r->date, 'home' => (float) $r->home, 'away' => (float) $r->away], $rows);
    }

    public function rankChange(string $stateCode, string $fromDate): int
    {
        $rows = DB::select(
            "SELECT r.rank FROM state_results r JOIN battles b ON b.id = r.battle_id
             WHERE r.state_code = ? AND b.kind = 'daily' AND b.status = 'finalized' AND b.battle_date >= ? AND r.rank IS NOT NULL
             ORDER BY b.battle_date ASC",
            [$stateCode, $fromDate],
        );
        if (count($rows) < 2) {
            return 0;
        }

        return (int) $rows[0]->rank - (int) $rows[count($rows) - 1]->rank;
    }

    public function approvedBanter(): array
    {
        return array_map(fn ($r) => $r->template, DB::select('SELECT template FROM banter_lines WHERE approved = true'));
    }
}
