<?php

namespace App\Shared\Contracts;

/** contracts.ts PublicQuestion. Never carries the answer. */
final readonly class PublicQuestion
{
    /** @param list<string> $options display order for this player */
    public function __construct(
        public int $index,
        public string $subject,
        public string $text,
        public ?string $highlight,
        public array $options,
        public int $timeLimitSec,
    ) {}

    public function toArray(): array
    {
        $out = ['index' => $this->index, 'subject' => $this->subject, 'text' => $this->text];
        if ($this->highlight !== null && $this->highlight !== '') {
            $out['highlight'] = $this->highlight;
        }
        $out['options'] = $this->options;
        $out['timeLimitSec'] = $this->timeLimitSec;

        return $out;
    }
}
