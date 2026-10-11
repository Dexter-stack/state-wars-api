<?php

namespace App\Shared\Contracts;

/** contracts.ts AnswerResult. correctIndex is in this player's display order. */
final readonly class AnswerResult
{
    public function __construct(public bool $correct, public int $correctIndex, public int $points, public ?PublicQuestion $next) {}

    public function toArray(): array
    {
        return ['correct' => $this->correct, 'correctIndex' => $this->correctIndex, 'points' => $this->points, 'next' => $this->next?->toArray()];
    }
}
