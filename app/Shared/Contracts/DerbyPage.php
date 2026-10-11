<?php

namespace App\Shared\Contracts;

/** contracts.ts DerbyPage */
final readonly class DerbyPage
{
    /**
     * @param  array{home: string, away: string, region: string, homeAvg: float|int, awayAvg: float|int, headToHead: string}|null  $tonight
     * @param  list<array{day: string, home: string, away: string, status: string, result?: string}>  $week
     * @param  array{vs: string, lead: float|int, dayOfWeek: int}|null  $rival
     */
    public function __construct(public ?array $tonight, public array $week, public ?array $rival) {}

    public function toArray(): array
    {
        return ['tonight' => $this->tonight, 'week' => $this->week, 'rival' => $this->rival];
    }
}
