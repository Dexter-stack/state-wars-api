<?php

namespace App\Modules\Questions\Repositories\Contracts;

use App\Modules\Questions\Repositories\QuestionRow;

interface QuestionsRepository
{
    /** @param array{subject: string, text: string, highlight: ?string, options: list<string>, answer: int, explanation: string, fun_headline: ?string, fun_banter: ?string, status: string, author_id: ?string, approved_by?: ?string} $q */
    public function create(array $q): QuestionRow;

    public function findById(string $id): ?QuestionRow;

    /** @param list<string> $ids @return list<QuestionRow> in the same order as $ids (missing ones left out) */
    public function getMany(array $ids): array;

    public function approve(string $id, string $approverId): ?QuestionRow;

    /** @return list<QuestionRow> */
    public function list(?string $status, ?string $subject, int $limit, int $offset): array;

    /** Approved questions not used in any battle since $notUsedSince (YYYY-MM-DD), random order. @return list<QuestionRow> */
    public function pickFresh(int $count, string $notUsedSince, ?string $subject): array;
}
