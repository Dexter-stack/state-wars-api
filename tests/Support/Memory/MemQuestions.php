<?php

namespace Tests\Support\Memory;

use App\Modules\Questions\Repositories\Contracts\QuestionsRepository;
use App\Modules\Questions\Repositories\QuestionRow;
use Illuminate\Support\Str;

final class MemQuestions implements QuestionsRepository
{
    /** @var array<string, QuestionRow> */
    public array $rows = [];

    public function create(array $q): QuestionRow
    {
        $row = new QuestionRow(
            (string) Str::uuid(), $q['subject'], $q['text'], $q['highlight'] ?? null, $q['options'], $q['answer'], $q['explanation'],
            $q['fun_headline'] ?? null, $q['fun_banter'] ?? null, $q['status'] ?? 'draft', $q['author_id'] ?? null,
            $q['approved_by'] ?? null, (int) floor(microtime(true) * 1000),
        );
        $this->rows[$row->id] = $row;

        return $row;
    }

    public function findById(string $id): ?QuestionRow
    {
        return $this->rows[$id] ?? null;
    }

    public function getMany(array $ids): array
    {
        return array_values(array_filter(array_map(fn ($id) => $this->rows[$id] ?? null, $ids)));
    }

    public function approve(string $id, string $approverId): ?QuestionRow
    {
        $r = $this->rows[$id] ?? null;
        if (! $r || $r->status !== 'draft') {
            return null;
        }
        $r->status = 'approved';
        $r->approvedBy = $approverId;

        return $r;
    }

    public function list(?string $status, ?string $subject, int $limit, int $offset): array
    {
        return array_values($this->rows);
    }

    public function pickFresh(int $count, string $notUsedSince, ?string $subject): array
    {
        return array_slice(array_values(array_filter($this->rows, fn ($r) => $r->status === 'approved')), 0, $count);
    }
}
