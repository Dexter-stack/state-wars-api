<?php

namespace App\Modules\Stats\Repositories;

use App\Modules\Stats\Repositories\Contracts\StatsRepository;
use Illuminate\Support\Facades\DB;

final class PgStatsRepository implements StatsRepository
{
    public function recentScores(string $userId, int $limit): array
    {
        $rows = DB::select(
            'SELECT score FROM entries WHERE user_id = ? AND finished_at IS NOT NULL ORDER BY started_at DESC LIMIT ?',
            [$userId, $limit],
        );

        return array_reverse(array_map(fn ($r) => (int) $r->score, $rows));
    }

    public function accuracyBySubject(string $userId): array
    {
        $rows = DB::select(
            'SELECT q.subject, round(100.0 * avg(CASE WHEN a.correct THEN 1 ELSE 0 END))::int AS pct
             FROM entry_answers a JOIN entries e ON e.id = a.entry_id JOIN questions q ON q.id = a.question_id
             WHERE e.user_id = ? GROUP BY q.subject HAVING count(*) >= 3',
            [$userId],
        );

        return array_map(fn ($r) => ['subject' => $r->subject, 'pct' => (int) $r->pct], $rows);
    }

    public function badges(string $userId): array
    {
        return array_map(fn ($r) => $r->badge, DB::select('SELECT badge FROM user_badges WHERE user_id = ?', [$userId]));
    }

    public function award(string $userId, array $badges): void
    {
        if ($badges === []) {
            return;
        }
        DB::table('user_badges')->insertOrIgnore(array_map(fn ($b) => ['user_id' => $userId, 'badge' => $b], $badges));
    }
}
