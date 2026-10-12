<?php

namespace Tests\Support\Memory;

use App\Modules\Leaderboard\Repositories\Contracts\ResultsRepository;

final class MemResults implements ResultsRepository
{
    /** @var array<string, list<array>> */
    public array $saved = [];

    public function upsert(string $battleId, array $rows): void
    {
        $this->saved[$battleId] = $rows;
    }

    public function finalRows(string $battleId): array
    {
        return $this->saved[$battleId] ?? [];
    }

    public function scoreCounts(string $battleId): array
    {
        return [];
    }

    public function previousRanks(string $kind, ?string $subject, string $beforeDate): array
    {
        return [];
    }

    public function aggregate(string $kind, ?string $subject, ?string $fromDate): array
    {
        return [];
    }

    public function headToHead(string $home, string $away, string $beforeDate, int $limit): array
    {
        return [];
    }

    public function rankChange(string $stateCode, string $fromDate): int
    {
        return 0;
    }

    public function approvedBanter(): array
    {
        return ['{state} is {rank} tonight.'];
    }
}
