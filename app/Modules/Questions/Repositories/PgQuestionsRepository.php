<?php

namespace App\Modules\Questions\Repositories;

use App\Core\Db\Pg;
use App\Modules\Questions\Repositories\Contracts\QuestionsRepository;
use Illuminate\Support\Facades\DB;

final class PgQuestionsRepository implements QuestionsRepository
{
    private const COLUMNS = 'id, subject, text, highlight, array_to_json(options) AS options, answer, explanation,
        fun_headline, fun_banter, status, author_id, approved_by, created_at';

    public function create(array $q): QuestionRow
    {
        $r = DB::selectOne(
            'INSERT INTO questions (subject, text, highlight, options, answer, explanation, fun_headline, fun_banter, status, author_id, approved_by)
             VALUES (?, ?, ?, '.Pg::arrayParam('text').', ?, ?, ?, ?, ?, ?, ?) RETURNING '.self::COLUMNS,
            [$q['subject'], $q['text'], $q['highlight'] ?? null, Pg::json($q['options']), $q['answer'], $q['explanation'],
                $q['fun_headline'] ?? null, $q['fun_banter'] ?? null, $q['status'] ?? 'draft', $q['author_id'] ?? null, $q['approved_by'] ?? null],
        );

        return self::row($r);
    }

    public function findById(string $id): ?QuestionRow
    {
        $r = DB::selectOne('SELECT '.self::COLUMNS.' FROM questions WHERE id = ?', [$id]);

        return $r ? self::row($r) : null;
    }

    public function getMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::select('SELECT '.self::COLUMNS.' FROM questions WHERE id = ANY('.Pg::arrayParam('uuid').')', [Pg::json($ids)]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r->id] = self::row($r);
        }

        // same order as $ids; missing ones left out
        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }

    public function approve(string $id, string $approverId): ?QuestionRow
    {
        $r = DB::selectOne(
            "UPDATE questions SET status = 'approved', approved_by = ? WHERE id = ? AND status = 'draft' RETURNING ".self::COLUMNS,
            [$approverId, $id],
        );

        return $r ? self::row($r) : null;
    }

    public function list(?string $status, ?string $subject, int $limit, int $offset): array
    {
        $where = [];
        $bindings = [];
        if ($status) {
            $where[] = 'status = ?';
            $bindings[] = $status;
        }
        if ($subject) {
            $where[] = 'subject = ?';
            $bindings[] = $subject;
        }
        $sql = 'SELECT '.self::COLUMNS.' FROM questions'.($where ? ' WHERE '.implode(' AND ', $where) : '')
            .' ORDER BY created_at DESC LIMIT ? OFFSET ?';

        return array_map(fn ($r) => self::row($r), DB::select($sql, [...$bindings, $limit, $offset]));
    }

    public function pickFresh(int $count, string $notUsedSince, ?string $subject): array
    {
        $sql = 'SELECT '.self::COLUMNS." FROM questions WHERE status = 'approved'
            AND NOT EXISTS (SELECT 1 FROM battles WHERE battle_date >= ? AND questions.id = ANY(battles.question_ids))"
            .($subject ? ' AND subject = ?' : '').' ORDER BY random() LIMIT ?';

        return array_map(fn ($r) => self::row($r), DB::select($sql, $subject ? [$notUsedSince, $subject, $count] : [$notUsedSince, $count]));
    }

    private static function row(object $r): QuestionRow
    {
        return new QuestionRow(
            id: $r->id,
            subject: $r->subject,
            text: $r->text,
            highlight: $r->highlight,
            options: Pg::decodeArray($r->options),
            answer: (int) $r->answer,
            explanation: $r->explanation,
            funHeadline: $r->fun_headline,
            funBanter: $r->fun_banter,
            status: $r->status,
            authorId: $r->author_id,
            approvedBy: $r->approved_by,
            createdAt: Pg::ms($r->created_at),
        );
    }
}
