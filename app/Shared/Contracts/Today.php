<?php

namespace App\Shared\Contracts;

/** contracts.ts Today (everything the Home screen needs in one call) */
final readonly class Today
{
    /**
     * @param  array{rank: int, move: int, average: float|int, players: int}  $myState
     * @param  array{home: string, away: string, note: string}|null  $derby
     * @param  array{entryId: string, finished: bool}|null  $myEntry  this player's entry for today's MAIN battle (not in the Node contract)
     */
    public function __construct(
        public ?string $battleId,
        public int $day,
        public string $status,
        public int $startsAt,
        public int $endsAt,
        public array $myState,
        public ?array $derby,
        public bool $isUprisingDay,
        public ?array $myEntry,
    ) {}

    public function toArray(): array
    {
        return [
            'battleId' => $this->battleId, 'day' => $this->day, 'status' => $this->status,
            'startsAt' => $this->startsAt, 'endsAt' => $this->endsAt,
            'myState' => [
                'rank' => $this->myState['rank'], 'move' => $this->myState['move'],
                'average' => $this->myState['average'], 'players' => $this->myState['players'],
            ],
            'derby' => $this->derby, 'isUprisingDay' => $this->isUprisingDay,
            'myEntry' => $this->myEntry,
        ];
    }
}
