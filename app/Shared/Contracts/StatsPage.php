<?php

namespace App\Shared\Contracts;

/** contracts.ts StatsPage */
final readonly class StatsPage
{
    /**
     * @param  list<int>  $history
     * @param  list<array{subject: string, pct: int}>  $accuracy
     * @param  list<array{name: string, earned: bool}>  $badges
     */
    public function __construct(
        public PlayerSummary $player,
        public array $history,
        public array $accuracy,
        public array $badges,
        public int $placesPushed,
    ) {}

    public function toArray(): array
    {
        return [
            'player' => $this->player->toArray(), 'history' => $this->history, 'accuracy' => $this->accuracy,
            'badges' => $this->badges, 'placesPushed' => $this->placesPushed,
        ];
    }
}
