<?php

namespace App\Modules\Leaderboard\Services;

use App\Core\Cache\Cache;
use App\Core\Config\GameConfig;
use App\Core\JsMath;
use App\Modules\Battles\Support\BattleView;
use App\Modules\Catalog\Catalog;
use App\Modules\Leaderboard\Repositories\Contracts\ResultsRepository;
use App\Modules\Leaderboard\Stores\Contracts\LiveScoreStore;

/**
 * The state table. Live totals in Redis while a battle runs; final rows in Postgres at close.
 * Table rows are arrays: {stateCode, state, players, average, ranked, rank, move} (cached as JSON).
 */
final class LeaderboardService
{
    private const FALLBACK_BANTER = '{state} is {rank} tonight. Una go come back stronger tomorrow?';

    public function __construct(
        private readonly LiveScoreStore $live,
        private readonly ResultsRepository $results,
        private readonly Cache $cache,
        private readonly GameConfig $cfg,
    ) {}

    /** Called exactly once per finished entry. Uprising states count extra on their day. */
    public function recordFinish(BattleView $b, string $stateCode, int $score): void
    {
        $weight = in_array($stateCode, $b->uprisingStates, true) ? $this->cfg->uprisingMultiplier : 1;
        $this->live->add($b->id, $stateCode, $score * $weight, $score);
    }

    /** Live table for a running battle. Cached 5s and shared by every player, so reads stay cheap at any volume. */
    public function liveTable(BattleView $b): array
    {
        return $this->cache->getOrSet("lb:{$b->id}", 5, fn () => $this->computeTable($b), 2_000);
    }

    private function computeTable(BattleView $b): array
    {
        $totals = $this->live->totals($b->id);
        $subject = $b->subject ?? '';
        $prev = $this->cache->getOrSet(
            "prevranks:{$b->kind}:{$subject}:{$b->date}", 600,
            fn () => $this->results->previousRanks($b->kind, $b->subject, $b->date),
        );
        $rows = [];
        foreach (Catalog::STATES as $s) {
            $t = $totals[$s['code']] ?? ['sum' => 0, 'count' => 0];
            $rows[] = [
                'stateCode' => $s['code'], 'state' => $s['name'], 'players' => (int) $t['count'],
                'average' => $t['count'] ? JsMath::round2($t['sum'] / $t['count']) : 0,
            ];
        }

        return $this->rank($rows, fn (string $code) => is_array($prev) ? ($prev[$code] ?? null) : null);
    }

    /** @param list<array{stateCode: string, state: string, players: int, average: float|int}> $rows */
    private function rank(array $rows, callable $prevRank): array
    {
        $min = $this->cfg->minPlayersRanked;
        $cmp = fn ($a, $b) => ($b['average'] <=> $a['average']) ?: ($b['players'] <=> $a['players']);
        $ranked = array_values(array_filter($rows, fn ($r) => $r['players'] >= $min));
        $unranked = array_values(array_filter($rows, fn ($r) => $r['players'] < $min));
        usort($ranked, $cmp); // stable, like Array.prototype.sort
        usort($unranked, $cmp);

        $out = [];
        foreach ($ranked as $i => $r) {
            $p = $prevRank($r['stateCode']);
            $out[] = $r + ['ranked' => true, 'rank' => $i + 1, 'move' => $p ? $p - ($i + 1) : 0];
        }
        foreach ($unranked as $r) {
            $out[] = $r + ['ranked' => false, 'rank' => null, 'move' => 0];
        }

        return $out;
    }

    /** Week and all-time tables from finalized results. Cached 60s. */
    public function periodTable(string $kind, ?string $subject, string $period, ?string $fromDate): array
    {
        $key = 'table:'.$kind.':'.($subject ?? '').':'.$period.':'.($fromDate ?? '');

        return $this->cache->getOrSet($key, 60, function () use ($kind, $subject, $fromDate) {
            $agg = [];
            foreach ($this->results->aggregate($kind, $subject, $fromDate) as $r) {
                $agg[$r['stateCode']] = $r;
            }
            $rows = [];
            foreach (Catalog::STATES as $s) {
                $rows[] = [
                    'stateCode' => $s['code'], 'state' => $s['name'],
                    'players' => $agg[$s['code']]['players'] ?? 0, 'average' => $agg[$s['code']]['average'] ?? 0,
                ];
            }

            return $this->rank($rows, fn () => null);
        });
    }

    /**
     * A state's live position. The shared table is cached for a few seconds; a player who just finished
     * should still see their own state counted, so recompute when the cached copy doesn't include it yet.
     *
     * @return array{rank: int, move: int, average: float|int, players: int}
     */
    public function stateSnapshot(BattleView $b, string $stateCode, bool $mustInclude = false): array
    {
        $find = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r['stateCode'] === $stateCode))[0] ?? null;
        $row = $find($this->liveTable($b));
        if ($mustInclude && (! $row || $row['players'] === 0)) {
            $row = $find($this->computeTable($b));
        }

        return [
            'rank' => $row['rank'] ?? 0, 'move' => $row['move'] ?? 0,
            'average' => $row['average'] ?? 0, 'players' => $row['players'] ?? 0,
        ];
    }

    /** "Top X%": share of players who scored at least as well as $score. */
    public function percentile(string $battleId, int $score): int
    {
        $total = 0;
        $atLeast = 0;
        foreach ($this->live->histogram($battleId) as $s => $c) {
            $total += $c;
            if ($s >= $score) {
                $atLeast += $c;
            }
        }
        if ($total === 0) {
            return 100;
        }

        return max(1, (int) ceil(($atLeast / $total) * 100));
    }

    /** One pre-written line about a rival state, chosen from approved templates. Never built from player input. */
    public function banterFor(BattleView $b, string $myState): string
    {
        $rows = $this->liveTable($b);
        $templates = $this->cache->getOrSet('banter:approved', 600, fn () => $this->results->approvedBanter());
        $others = array_values(array_filter($rows, fn ($r) => $r['ranked'] && $r['stateCode'] !== $myState));
        if ($others === []) {
            return Catalog::stateName($myState).' is on the board. Bring your people, the table needs more players.';
        }
        usort($others, fn ($a, $c) => ($a['move'] <=> $c['move']) ?: (($c['rank'] ?? 0) <=> ($a['rank'] ?? 0)));
        $target = $others[0];
        $pool = $templates ?: [self::FALLBACK_BANTER];
        $t = $pool[self::hash("{$b->id}:{$myState}") % count($pool)];

        return str_replace(['{state}', '{rank}'], [$target['state'], Catalog::ordinal($target['rank'])], $t);
    }

    /** Writes final results. Idempotent: safe to run again. */
    public function finalize(BattleView $b): array
    {
        $this->cache->del("lb:{$b->id}");
        $rows = $this->computeTable($b);
        $final = [];
        foreach ($rows as $r) {
            if ($r['players'] > 0) {
                $final[] = ['stateCode' => $r['stateCode'], 'players' => $r['players'], 'average' => $r['average'], 'rank' => $r['rank'], 'move' => $r['move'], 'banter' => null];
            }
        }
        $this->results->upsert($b->id, $final);

        return $rows;
    }

    /** The bottom N ranked states of a finalized battle: they get the Uprising boost next Friday. @return list<string> */
    public function bottomStates(BattleView $b): array
    {
        $ranked = array_values(array_filter($this->computeTable($b), fn ($r) => $r['ranked']));

        return array_column(array_slice($ranked, -$this->cfg->uprisingCount), 'stateCode');
    }

    /** Same as the Node hash: h = (h * 31 + charCode) >>> 0 */
    private static function hash(string $s): int
    {
        $h = 0;
        foreach (mb_str_split($s) as $ch) {
            $h = ($h * 31 + mb_ord($ch)) & 0xFFFFFFFF;
        }

        return $h;
    }
}
