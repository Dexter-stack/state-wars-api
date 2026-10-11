<?php

namespace App\Modules\Questions\Services;

use App\Core\Errors;
use App\Modules\Catalog\Catalog;
use App\Modules\Questions\Repositories\Contracts\QuestionsRepository;
use App\Modules\Questions\Repositories\QuestionRow;

/** The question bank. Every question needs a second person to approve it before it can be used. */
final class QuestionsService
{
    public function __construct(private readonly QuestionsRepository $questions) {}

    /**
     * @param  array{subject: string, text: string, highlight?: ?string, options: list<string>, answer: int, explanation: string,
     *               funHeadline?: ?string, funBanter?: ?string}  $q
     */
    public function create(string $authorId, array $q): QuestionRow
    {
        if (! Catalog::isSubject($q['subject'])) {
            throw Errors::badRequest("Unknown subject \"{$q['subject']}\".");
        }
        $distinct = array_unique(array_map(fn ($o) => mb_strtolower(trim($o)), $q['options']));
        if (count($distinct) !== 4) {
            throw Errors::badRequest('The 4 options must all be different.');
        }
        $highlight = $q['highlight'] ?? null;
        if ($highlight && ! str_contains($q['text'], $highlight)) {
            throw Errors::badRequest('The highlighted word must appear in the question text.');
        }

        return $this->questions->create([
            'subject' => $q['subject'], 'text' => $q['text'], 'highlight' => $highlight ?? null, 'options' => $q['options'],
            'answer' => $q['answer'], 'explanation' => $q['explanation'], 'fun_headline' => $q['funHeadline'] ?? null,
            'fun_banter' => $q['funBanter'] ?? null, 'status' => 'draft', 'author_id' => $authorId,
        ]);
    }

    public function approve(string $approverId, string $id): QuestionRow
    {
        $q = $this->questions->findById($id);
        if (! $q) {
            throw Errors::notFound('Question');
        }
        if ($q->status !== 'draft') {
            throw Errors::conflict('not_draft', 'Only draft questions can be approved.');
        }
        if ($q->authorId === $approverId) {
            throw Errors::forbidden('Someone other than the author must approve this question.');
        }
        $out = $this->questions->approve($id, $approverId);
        if (! $out) {
            throw Errors::conflict('not_draft', 'This question was already approved.');
        }

        return $out;
    }

    /** @return list<QuestionRow> */
    public function list(?string $status, ?string $subject, int $limit, int $offset): array
    {
        return $this->questions->list($status, $subject, $limit, $offset);
    }
}
