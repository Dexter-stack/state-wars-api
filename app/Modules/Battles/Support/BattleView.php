<?php

namespace App\Modules\Battles\Support;

/** A battle in cache-friendly form (ms numbers instead of dates, so it survives JSON in Redis). Port of battle.view.ts. */
final readonly class BattleView
{
    /**
     * @param  list<string>  $questionIds
     * @param  list<string>  $uprisingStates
     */
    public function __construct(
        public string $id,
        public string $date,
        public string $kind,          // daily | league
        public ?string $subject,
        public int $dayNumber,
        public int $opensAt,
        public int $closesAt,         // last moment a player can START
        public array $questionIds,
        public array $uprisingStates,
        public string $status,        // scheduled | finalized
    ) {}

    /** When the last possible player has finished: everyone who started before close has had time for every question. */
    public function finalizeAt(int $limitSec, int $graceMs, int $pauseBudgetMs): int
    {
        return $this->closesAt + count($this->questionIds) * ($limitSec * 1000 + $graceMs) + $pauseBudgetMs + 60_000;
    }

    /** Same keys as the Node BattleView (also the POST /v1/admin/battles response). */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'kind' => $this->kind,
            'subject' => $this->subject,
            'dayNumber' => $this->dayNumber,
            'opensAt' => $this->opensAt,
            'closesAt' => $this->closesAt,
            'questionIds' => $this->questionIds,
            'uprisingStates' => $this->uprisingStates,
            'status' => $this->status,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            $a['id'], $a['date'], $a['kind'], $a['subject'], (int) $a['dayNumber'], (int) $a['opensAt'], (int) $a['closesAt'],
            array_values($a['questionIds']), array_values($a['uprisingStates']), $a['status'],
        );
    }
}
