<?php

namespace App\Modules\Battles\Repositories;

/** An entries row (one player's play of one battle). Times are ms epoch. */
final class EntryRow
{
    public function __construct(
        public string $id,
        public string $battleId,
        public string $userId,
        public string $stateCode,
        public int $seed,
        public int $startedAt,
        public ?int $finishedAt,
        public int $currentIndex,
        public int $currentSentAt,
        public int $pauseUsedMs,
        public int $score,
        public int $points,
    ) {}
}
