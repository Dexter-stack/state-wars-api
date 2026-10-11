<?php

namespace App\Shared\Contracts;

/** contracts.ts Resume */
final readonly class Resume
{
    /** @param list<bool> $answered */
    public function __construct(
        public string $battleId,
        public int $total,
        public array $answered,
        public ?PublicQuestion $question,
        public int $remainingMs,
        public bool $finished,
    ) {}

    public function toArray(): array
    {
        return [
            'battleId' => $this->battleId, 'total' => $this->total, 'answered' => $this->answered,
            'question' => $this->question?->toArray(), 'remainingMs' => $this->remainingMs, 'finished' => $this->finished,
        ];
    }
}
