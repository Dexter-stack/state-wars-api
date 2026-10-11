<?php

namespace App\Modules\Users\Repositories;

/** A users row. Timestamps are ms epoch; last_played_date is a WAT date "YYYY-MM-DD". */
final class UserRow
{
    public function __construct(
        public string $id,
        public string $username,
        public string $stateCode,
        public int $stateChangedAt,
        public string $role,
        public int $points,
        public int $streak,
        public int $bestStreak,
        public ?string $lastPlayedDate,
        public bool $remind,
        public int $createdAt,
    ) {}
}
