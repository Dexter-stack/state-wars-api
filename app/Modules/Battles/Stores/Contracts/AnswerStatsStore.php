<?php

namespace App\Modules\Battles\Stores\Contracts;

/** Live counts of which option players chose per question, for "How Nigeria answered". O(1) per answer. */
interface AnswerStatsStore
{
    public function record(string $battleId, string $questionId, ?int $canonicalChoice): void;

    /** @return array{counts: list<int>, none: int} counts[0..3] per canonical option; none = no answer */
    public function distribution(string $battleId, string $questionId): array;
}
