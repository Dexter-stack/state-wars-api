<?php

namespace App\Modules\Derbies\Repositories;

use App\Modules\Derbies\Repositories\Contracts\DerbiesRepository;
use Illuminate\Support\Facades\DB;

final class PgDerbiesRepository implements DerbiesRepository
{
    private const SELECT = 'SELECT id, derby_date::text AS derby_date, home, away, region FROM derbies';

    public function forDate(string $date): ?array
    {
        $r = DB::selectOne(self::SELECT.' WHERE derby_date = ?', [$date]);

        return $r ? self::row($r) : null;
    }

    public function between(string $from, string $to): array
    {
        return array_map(fn ($r) => self::row($r), DB::select(self::SELECT.' WHERE derby_date >= ? AND derby_date <= ? ORDER BY derby_date', [$from, $to]));
    }

    private static function row(object $r): array
    {
        return ['id' => (int) $r->id, 'date' => $r->derby_date, 'home' => $r->home, 'away' => $r->away, 'region' => $r->region];
    }
}
