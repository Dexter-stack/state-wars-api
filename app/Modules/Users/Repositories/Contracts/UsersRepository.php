<?php

namespace App\Modules\Users\Repositories\Contracts;

use App\Modules\Users\Repositories\UserRow;

interface UsersRepository
{
    public function findById(string $id): ?UserRow;

    public function usernameTaken(string $username): bool;

    /** @throws \App\Core\AppException 409 username_taken when a concurrent sign-up got the name first */
    public function create(string $username, string $stateCode, bool $remind): UserRow;

    public function updateState(string $id, string $stateCode, int $atMs): void;

    public function updateRemind(string $id, bool $remind): void;

    /**
     * Atomically adds points and updates the daily streak. Safe to call once per finished battle.
     *
     * @return array{streak: int, bestStreak: int, points: int}
     */
    public function applyBattleResult(string $id, string $battleDate, int $points): array;

    /** @return array{battles: int, average: float|int} */
    public function battleSummary(string $id): array;
}
