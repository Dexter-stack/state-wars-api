<?php

namespace Tests\Support\Memory;

use App\Modules\Battles\Stores\Contracts\AnswerStatsStore;

final class MemoryAnswerStatsStore implements AnswerStatsStore
{
    private array $m = [];

    public function record(string $battleId, string $questionId, ?int $canonicalChoice): void
    {
        $k = "{$battleId}:{$questionId}";
        $arr = $this->m[$k] ?? [0, 0, 0, 0, 0];
        $arr[$canonicalChoice ?? 4]++;
        $this->m[$k] = $arr;
    }

    public function distribution(string $battleId, string $questionId): array
    {
        $arr = $this->m["{$battleId}:{$questionId}"] ?? [0, 0, 0, 0, 0];

        return ['counts' => array_slice($arr, 0, 4), 'none' => $arr[4]];
    }
}
