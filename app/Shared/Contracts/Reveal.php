<?php

namespace App\Shared\Contracts;

/** contracts.ts Reveal ("How Nigeria answered") */
final readonly class Reveal
{
    /**
     * @param  list<array{text: string, pct: int}>  $options
     * @param  array{headline: string, banter: string}|null  $funStat
     */
    public function __construct(
        public int $index,
        public int $total,
        public string $subject,
        public string $text,
        public array $options,
        public int $correctIndex,
        public ?int $yourChoice,
        public string $explanation,
        public ?array $funStat,
    ) {}

    public function toArray(): array
    {
        $out = [
            'index' => $this->index, 'total' => $this->total, 'subject' => $this->subject, 'text' => $this->text,
            'options' => $this->options, 'correctIndex' => $this->correctIndex, 'yourChoice' => $this->yourChoice,
            'explanation' => $this->explanation,
        ];
        if ($this->funStat !== null) {
            $out['funStat'] = $this->funStat;
        }

        return $out;
    }
}
