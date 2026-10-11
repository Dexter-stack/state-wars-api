<?php

namespace Tests\Support\Memory;

use App\Modules\Battles\Repositories\Contracts\BattlesRepository;
use App\Modules\Battles\Support\BattleView;
use Illuminate\Support\Str;

final class MemBattles implements BattlesRepository
{
    /** @var array<string, BattleView> */
    public array $rows = [];

    public function findById(string $id): ?BattleView
    {
        return $this->rows[$id] ?? null;
    }

    public function findSlot(string $date, string $kind, ?string $subject): ?BattleView
    {
        foreach ($this->rows as $b) {
            if ($b->date === $date && $b->kind === $kind && $b->subject === $subject) {
                return $b;
            }
        }

        return null;
    }

    public function findOpen(string $kind, ?string $subject, int $nowMs, bool $anyTime): ?BattleView
    {
        $c = array_values(array_filter($this->rows, fn ($b) => $b->kind === $kind && $b->subject === $subject
            && ($anyTime || ($b->opensAt <= $nowMs && $b->closesAt > $nowMs))));
        usort($c, fn ($a, $b) => $b->opensAt <=> $a->opensAt);

        return $c[0] ?? null;
    }

    public function findNextAfter(string $kind, ?string $subject, int $nowMs): ?BattleView
    {
        $c = array_values(array_filter($this->rows, fn ($b) => $b->kind === $kind && $b->subject === $subject && $b->opensAt > $nowMs));
        usort($c, fn ($a, $b) => $a->opensAt <=> $b->opensAt);

        return $c[0] ?? null;
    }

    public function create(string $date, string $kind, ?string $subject, int $dayNumber, int $opensAt, int $closesAt, array $questionIds, array $uprisingStates): BattleView
    {
        $existing = $this->findSlot($date, $kind, $subject);
        if ($existing) {
            return $existing;
        }
        $b = new BattleView((string) Str::uuid(), $date, $kind, $subject, $dayNumber, $opensAt, $closesAt, $questionIds, $uprisingStates, 'scheduled');
        $this->rows[$b->id] = $b;

        return $b;
    }

    public function listScheduledClosedBefore(int $cutoffMs): array
    {
        return array_values(array_filter($this->rows, fn ($b) => $b->status === 'scheduled' && $b->closesAt <= $cutoffMs));
    }

    public function markFinalized(string $id): void
    {
        $b = $this->rows[$id];
        $this->rows[$id] = new BattleView($b->id, $b->date, $b->kind, $b->subject, $b->dayNumber, $b->opensAt, $b->closesAt, $b->questionIds, $b->uprisingStates, 'finalized');
    }
}
