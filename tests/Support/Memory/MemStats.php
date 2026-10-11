<?php

namespace Tests\Support\Memory;

use App\Modules\Stats\Repositories\Contracts\StatsRepository;

final class MemStats implements StatsRepository
{
    /** @var array<string, array<string, true>> */
    public array $awarded = [];

    public function recentScores(string $userId, int $limit): array
    {
        return [];
    }

    public function accuracyBySubject(string $userId): array
    {
        return [];
    }

    public function badges(string $userId): array
    {
        return array_keys($this->awarded[$userId] ?? []);
    }

    public function award(string $userId, array $badges): void
    {
        foreach ($badges as $b) {
            $this->awarded[$userId][$b] = true;
        }
    }
}
