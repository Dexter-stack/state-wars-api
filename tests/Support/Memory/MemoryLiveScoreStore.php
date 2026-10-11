<?php

namespace Tests\Support\Memory;

use App\Modules\Leaderboard\Stores\Contracts\LiveScoreStore;

final class MemoryLiveScoreStore implements LiveScoreStore
{
    private array $t = [];

    private array $h = [];

    public function add(string $battleId, string $stateCode, float|int $weightedScore, int $rawScore): void
    {
        $cur = $this->t[$battleId][$stateCode] ?? ['sum' => 0, 'count' => 0];
        $this->t[$battleId][$stateCode] = ['sum' => $cur['sum'] + $weightedScore, 'count' => $cur['count'] + 1];
        $this->h[$battleId][$rawScore] = ($this->h[$battleId][$rawScore] ?? 0) + 1;
    }

    public function totals(string $battleId): array
    {
        return $this->t[$battleId] ?? [];
    }

    public function histogram(string $battleId): array
    {
        return $this->h[$battleId] ?? [];
    }
}
