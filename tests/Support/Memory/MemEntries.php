<?php

namespace Tests\Support\Memory;

use App\Modules\Battles\Repositories\Contracts\EntriesRepository;
use App\Modules\Battles\Repositories\EntryAnswerRow;
use App\Modules\Battles\Repositories\EntryRow;
use Illuminate\Support\Str;

final class MemEntries implements EntriesRepository
{
    /** @var array<string, EntryRow> */
    public array $rows = [];

    /** @var array<string, EntryAnswerRow> */
    public array $answers = [];

    public function findById(string $id): ?EntryRow
    {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function findForUser(string $battleId, string $userId): ?EntryRow
    {
        foreach ($this->rows as $e) {
            if ($e->battleId === $battleId && $e->userId === $userId) {
                return clone $e;
            }
        }

        return null;
    }

    public function create(string $battleId, string $userId, string $stateCode, int $seed, int $nowMs): EntryRow
    {
        $ex = $this->findForUser($battleId, $userId);
        if ($ex) {
            return $ex;
        }
        $row = new EntryRow((string) Str::uuid(), $battleId, $userId, $stateCode, $seed, $nowMs, null, 0, $nowMs, 0, 0, 0);
        $this->rows[$row->id] = $row;

        return clone $row;
    }

    public function recordAnswer(EntryAnswerRow $a): bool
    {
        $k = "{$a->entryId}:{$a->qIndex}";
        $e = $this->rows[$a->entryId];
        if (isset($this->answers[$k]) || $e->currentIndex !== $a->qIndex || $e->finishedAt !== null) {
            return false;
        }
        $this->answers[$k] = $a;
        $e->currentIndex = $a->qIndex + 1;
        $e->currentSentAt = $a->answeredAt;
        $e->score += $a->correct ? 1 : 0;
        $e->points += $a->points;

        return true;
    }

    public function getAnswer(string $entryId, int $index): ?EntryAnswerRow
    {
        return $this->answers["{$entryId}:{$index}"] ?? null;
    }

    public function listAnswers(string $entryId): array
    {
        $out = array_values(array_filter($this->answers, fn ($a) => $a->entryId === $entryId));
        usort($out, fn ($a, $b) => $a->qIndex <=> $b->qIndex);

        return $out;
    }

    public function extendCurrent(string $entryId, int $index, int $shiftMs): bool
    {
        $e = $this->rows[$entryId];
        if ($e->currentIndex !== $index || $e->finishedAt !== null) {
            return false;
        }
        $e->currentSentAt += $shiftMs;
        $e->pauseUsedMs += $shiftMs;

        return true;
    }

    public function finish(string $entryId, int $atMs): bool
    {
        $e = $this->rows[$entryId];
        if ($e->finishedAt !== null) {
            return false;
        }
        $e->finishedAt = $atMs;

        return true;
    }

    public function listUnfinished(string $battleId, int $limit): array
    {
        $out = array_values(array_filter($this->rows, fn ($e) => $e->battleId === $battleId && $e->finishedAt === null));

        return array_map(fn ($e) => clone $e, array_slice($out, 0, $limit));
    }

    public function latestFinished(string $userId): ?EntryRow
    {
        $out = array_values(array_filter($this->rows, fn ($e) => $e->userId === $userId && $e->finishedAt !== null));
        usort($out, fn ($a, $b) => $b->startedAt <=> $a->startedAt);

        return isset($out[0]) ? clone $out[0] : null;
    }
}
