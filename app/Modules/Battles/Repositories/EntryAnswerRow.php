<?php

namespace App\Modules\Battles\Repositories;

/** An entry_answers row. $choice is the CANONICAL option index (not the shuffled display index), null = no answer in time. */
final readonly class EntryAnswerRow
{
    public function __construct(
        public string $entryId,
        public int $qIndex,
        public string $questionId,
        public ?int $choice,
        public bool $correct,
        public int $points,
        public int $sentAt,
        public int $answeredAt,
    ) {}
}
