<?php

namespace App\Modules\Stats\Repositories\Contracts;

interface StatsRepository
{
    /** @return list<int> oldest first */
    public function recentScores(string $userId, int $limit): array;

    /** @return list<array{subject: string, pct: int}> */
    public function accuracyBySubject(string $userId): array;

    /** @return list<string> */
    public function badges(string $userId): array;

    /** @param list<string> $badges */
    public function award(string $userId, array $badges): void;
}
