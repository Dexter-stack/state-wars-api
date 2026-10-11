<?php

namespace App\Modules\Today\Services;

use App\Modules\Battles\Support\BattleView;

/** Finds "today's battle" for screens and tables. */
interface BattleLocator
{
    public function todaysBattle(string $kind, ?string $subject): ?BattleView;
}
