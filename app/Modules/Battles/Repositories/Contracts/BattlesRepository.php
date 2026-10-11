<?php

namespace App\Modules\Battles\Repositories\Contracts;

use App\Modules\Battles\Support\BattleView;

interface BattlesRepository
{
    public function findById(string $id): ?BattleView;

    public function findSlot(string $date, string $kind, ?string $subject): ?BattleView;

    /** The battle accepting new players at $nowMs. With $anyTime, the most recent one regardless of time (development). */
    public function findOpen(string $kind, ?string $subject, int $nowMs, bool $anyTime): ?BattleView;

    /** The next battle that opens after $nowMs. */
    public function findNextAfter(string $kind, ?string $subject, int $nowMs): ?BattleView;

    /**
     * Creates the battle, or returns the existing one for that slot.
     *
     * @param  list<string>  $questionIds
     * @param  list<string>  $uprisingStates
     */
    public function create(string $date, string $kind, ?string $subject, int $dayNumber, int $opensAt, int $closesAt, array $questionIds, array $uprisingStates): BattleView;

    /** @return list<BattleView> */
    public function listScheduledClosedBefore(int $cutoffMs): array;

    public function markFinalized(string $id): void;
}
