<?php

namespace App\Modules\Battles\Repositories\Contracts;

use App\Modules\Battles\Repositories\EntryAnswerRow;
use App\Modules\Battles\Repositories\EntryRow;

interface EntriesRepository
{
    public function findById(string $id): ?EntryRow;

    public function findForUser(string $battleId, string $userId): ?EntryRow;

    /** Creates the entry, or returns the existing one if this player already started (safe under double-taps). */
    public function create(string $battleId, string $userId, string $stateCode, int $seed, int $nowMs): EntryRow;

    /**
     * Stores the answer to question $a->qIndex and moves the entry to the next question, in one transaction.
     * Only succeeds if that index is still the current question: returns false when a duplicate request got there first.
     */
    public function recordAnswer(EntryAnswerRow $a): bool;

    public function getAnswer(string $entryId, int $index): ?EntryAnswerRow;

    /** @return list<EntryAnswerRow> ordered by q_index */
    public function listAnswers(string $entryId): array;

    /** Pushes the current question's deadline back by $shiftMs, using up pause budget. */
    public function extendCurrent(string $entryId, int $index, int $shiftMs): bool;

    /** Marks the entry finished. Returns true only for the call that actually finished it (exactly-once side effects). */
    public function finish(string $entryId, int $atMs): bool;

    /** @return list<EntryRow> */
    public function listUnfinished(string $battleId, int $limit): array;

    public function latestFinished(string $userId): ?EntryRow;
}
