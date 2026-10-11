<?php

namespace App\Modules\Derbies\Repositories\Contracts;

/** Derby rows are arrays: {id: int, date: "YYYY-MM-DD", home: code, away: code, region: string}. */
interface DerbiesRepository
{
    /** @return array{id: int, date: string, home: string, away: string, region: string}|null */
    public function forDate(string $date): ?array;

    /** @return list<array{id: int, date: string, home: string, away: string, region: string}> ordered by date */
    public function between(string $from, string $to): array;
}
