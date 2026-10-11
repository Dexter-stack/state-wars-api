<?php

namespace App\Modules\Leaderboard\Repositories\Contracts;

interface ResultsRepository
{
    /** @param list<array{stateCode: string, players: int, average: float|int, rank: ?int, move: int, banter: ?string}> $rows */
    public function upsert(string $battleId, array $rows): void;

    /** Final ranks of the most recent finalized battle of this kind before $beforeDate. @return array<string, int> */
    public function previousRanks(string $kind, ?string $subject, string $beforeDate): array;

    /** Weighted averages across finalized battles since $fromDate (null = all time). @return list<array{stateCode: string, players: int, average: float|int}> */
    public function aggregate(string $kind, ?string $subject, ?string $fromDate): array;

    /** Final daily averages for two states on derby nights, newest first. @return list<array{date: string, home: float, away: float}> */
    public function headToHead(string $home, string $away, string $beforeDate, int $limit): array;

    /** A state's final daily rank on the first and last night since $fromDate (first - last). */
    public function rankChange(string $stateCode, string $fromDate): int;

    /** @return list<string> */
    public function approvedBanter(): array;
}
