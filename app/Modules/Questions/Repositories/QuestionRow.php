<?php

namespace App\Modules\Questions\Repositories;

use App\Core\Db\Pg;

/** A questions row. */
final class QuestionRow
{
    /** @param list<string> $options */
    public function __construct(
        public string $id,
        public string $subject,
        public string $text,
        public ?string $highlight,
        public array $options,
        public int $answer,
        public string $explanation,
        public ?string $funHeadline,
        public ?string $funBanter,
        public string $status,
        public ?string $authorId,
        public ?string $approvedBy,
        public int $createdAt,
    ) {}

    /** The raw row as the Node admin endpoints return it (snake_case, ISO created_at). */
    public function toRowArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'text' => $this->text,
            'highlight' => $this->highlight,
            'options' => $this->options,
            'answer' => $this->answer,
            'explanation' => $this->explanation,
            'fun_headline' => $this->funHeadline,
            'fun_banter' => $this->funBanter,
            'status' => $this->status,
            'author_id' => $this->authorId,
            'approved_by' => $this->approvedBy,
            'created_at' => Pg::ts($this->createdAt),
        ];
    }
}
