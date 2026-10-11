<?php

namespace App\Shared\Contracts;

/** contracts.ts PlayerSummary. Must match state-wars-web/src/api/types.ts. */
final readonly class PlayerSummary
{
    public function __construct(
        public string $username,
        public string $stateCode,
        public string $rank,
        public int $pointsToNext,
        public int $streak,
        public int $bestStreak,
        public int $battles,
        public float|int $average,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'stateCode' => $this->stateCode,
            'rank' => $this->rank,
            'pointsToNext' => $this->pointsToNext,
            'streak' => $this->streak,
            'bestStreak' => $this->bestStreak,
            'battles' => $this->battles,
            'average' => $this->average,
        ];
    }
}
