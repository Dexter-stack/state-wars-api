<?php

namespace App\Shared\Contracts;

/** contracts.ts BattleStart. battleId is the ENTRY id (kept for answers, finish and reveal). */
final readonly class BattleStart
{
    public function __construct(public string $battleId, public int $total, public PublicQuestion $question, public int $remainingMs) {}

    public function toArray(): array
    {
        return ['battleId' => $this->battleId, 'total' => $this->total, 'question' => $this->question->toArray(), 'remainingMs' => $this->remainingMs];
    }
}
