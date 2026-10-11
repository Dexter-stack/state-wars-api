<?php

namespace App\Shared\Contracts;

/** contracts.ts BattleResult */
final readonly class BattleResult
{
    /** @param list<bool> $answers */
    public function __construct(
        public int $day,
        public int $score,
        public int $total,
        public array $answers,
        public int $points,
        public string $stateCode,
        public int $stateRank,
        public int $stateMove,
        public string $banter,
        public int $streak,
        public string $rank,
        public int $percentile,
    ) {}

    public function toArray(): array
    {
        return [
            'day' => $this->day, 'score' => $this->score, 'total' => $this->total, 'answers' => $this->answers,
            'points' => $this->points, 'stateCode' => $this->stateCode, 'stateRank' => $this->stateRank,
            'stateMove' => $this->stateMove, 'banter' => $this->banter, 'streak' => $this->streak,
            'rank' => $this->rank, 'percentile' => $this->percentile,
        ];
    }
}
