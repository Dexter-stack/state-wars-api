<?php

namespace Tests\Support\Memory;

use App\Core\WatTime;
use App\Modules\Users\Repositories\Contracts\UsersRepository;
use App\Modules\Users\Repositories\UserRow;
use Illuminate\Support\Str;

final class MemUsers implements UsersRepository
{
    /** @var array<string, UserRow> */
    public array $rows = [];

    public function findById(string $id): ?UserRow
    {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function usernameTaken(string $username): bool
    {
        foreach ($this->rows as $r) {
            if (strtolower($r->username) === strtolower($username)) {
                return true;
            }
        }

        return false;
    }

    public function create(string $username, string $stateCode, bool $remind): UserRow
    {
        $now = (int) floor(microtime(true) * 1000);
        $row = new UserRow((string) Str::uuid(), $username, $stateCode, $now, 'player', 0, 0, 0, null, $remind, $now);
        $this->rows[$row->id] = $row;

        return clone $row;
    }

    public function updateState(string $id, string $stateCode, int $atMs): void
    {
        $this->rows[$id]->stateCode = $stateCode;
        $this->rows[$id]->stateChangedAt = $atMs;
    }

    public function updateRemind(string $id, bool $remind): void
    {
        $this->rows[$id]->remind = $remind;
    }

    public function applyBattleResult(string $id, string $battleDate, int $points): array
    {
        $r = $this->rows[$id];
        $s = $r->lastPlayedDate === $battleDate ? $r->streak
            : ($r->lastPlayedDate === WatTime::addDays($battleDate, -1) ? $r->streak + 1 : 1);
        $r->streak = $s;
        $r->bestStreak = max($r->bestStreak, $s);
        $r->points += $points;
        $r->lastPlayedDate = $battleDate;

        return ['streak' => $r->streak, 'bestStreak' => $r->bestStreak, 'points' => $r->points];
    }

    public function battleSummary(string $id): array
    {
        return ['battles' => 0, 'average' => 0];
    }
}
