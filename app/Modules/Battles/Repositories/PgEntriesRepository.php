<?php

namespace App\Modules\Battles\Repositories;

use App\Core\Db\Pg;
use App\Modules\Battles\Repositories\Contracts\EntriesRepository;
use Illuminate\Support\Facades\DB;

/** The hot path at 8pm: Query Builder, one read plus one small transaction per answer. */
final class PgEntriesRepository implements EntriesRepository
{
    private const COLUMNS = ['id', 'battle_id', 'user_id', 'state_code', 'seed', 'started_at', 'finished_at',
        'current_index', 'current_sent_at', 'pause_used_ms', 'score', 'points'];

    public function findById(string $id): ?EntryRow
    {
        $r = DB::table('entries')->select(self::COLUMNS)->where('id', $id)->first();

        return $r ? self::row($r) : null;
    }

    public function findForUser(string $battleId, string $userId): ?EntryRow
    {
        $r = DB::table('entries')->select(self::COLUMNS)->where('battle_id', $battleId)->where('user_id', $userId)->first();

        return $r ? self::row($r) : null;
    }

    public function create(string $battleId, string $userId, string $stateCode, int $seed, int $nowMs): EntryRow
    {
        $now = Pg::ts($nowMs);
        $r = DB::selectOne(
            'INSERT INTO entries (battle_id, user_id, state_code, seed, started_at, current_sent_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (battle_id, user_id) DO NOTHING RETURNING '.implode(', ', self::COLUMNS),
            [$battleId, $userId, $stateCode, $seed, $now, $now],
        );

        return $r ? self::row($r) : $this->findForUser($battleId, $userId);
    }

    public function recordAnswer(EntryAnswerRow $a): bool
    {
        try {
            DB::transaction(function () use ($a) {
                // primary key (entry_id, q_index): a duplicate request inserts nothing
                $inserted = DB::table('entry_answers')->insertOrIgnore([
                    'entry_id' => $a->entryId, 'q_index' => $a->qIndex, 'question_id' => $a->questionId, 'choice' => $a->choice,
                    'correct' => $a->correct, 'points' => $a->points, 'sent_at' => Pg::ts($a->sentAt), 'answered_at' => Pg::ts($a->answeredAt),
                ]);
                if ($inserted === 0) {
                    throw new LostRace;
                }

                // conditional on still being at this question: never moves twice, never moves a finished entry
                $updated = DB::table('entries')
                    ->where('id', $a->entryId)->where('current_index', $a->qIndex)->whereNull('finished_at')
                    ->update([
                        'current_index' => $a->qIndex + 1,
                        'current_sent_at' => Pg::ts($a->answeredAt),
                        'score' => DB::raw('score + '.($a->correct ? 1 : 0)),
                        'points' => DB::raw('points + '.(int) $a->points),
                    ]);
                if ($updated === 0) {
                    throw new LostRace; // rolls back the insert
                }
            });

            return true;
        } catch (LostRace) {
            return false;
        }
    }

    public function getAnswer(string $entryId, int $index): ?EntryAnswerRow
    {
        $r = DB::table('entry_answers')->where('entry_id', $entryId)->where('q_index', $index)->first();

        return $r ? self::answer($r) : null;
    }

    public function listAnswers(string $entryId): array
    {
        return DB::table('entry_answers')->where('entry_id', $entryId)->orderBy('q_index')->get()
            ->map(fn ($r) => self::answer($r))->all();
    }

    public function extendCurrent(string $entryId, int $index, int $shiftMs): bool
    {
        return DB::update(
            'UPDATE entries SET current_sent_at = current_sent_at + make_interval(secs => ?), pause_used_ms = pause_used_ms + ?
             WHERE id = ? AND current_index = ? AND finished_at IS NULL',
            [$shiftMs / 1000, $shiftMs, $entryId, $index],
        ) > 0;
    }

    public function finish(string $entryId, int $atMs): bool
    {
        return DB::table('entries')->where('id', $entryId)->whereNull('finished_at')->update(['finished_at' => Pg::ts($atMs)]) > 0;
    }

    public function listUnfinished(string $battleId, int $limit): array
    {
        return DB::table('entries')->select(self::COLUMNS)->where('battle_id', $battleId)->whereNull('finished_at')
            ->limit($limit)->get()->map(fn ($r) => self::row($r))->all();
    }

    public function latestFinished(string $userId): ?EntryRow
    {
        $r = DB::table('entries')->select(self::COLUMNS)->where('user_id', $userId)->whereNotNull('finished_at')
            ->orderByDesc('started_at')->first();

        return $r ? self::row($r) : null;
    }

    private static function row(object $r): EntryRow
    {
        return new EntryRow(
            id: $r->id,
            battleId: $r->battle_id,
            userId: $r->user_id,
            stateCode: $r->state_code,
            seed: (int) $r->seed,
            startedAt: Pg::ms($r->started_at),
            finishedAt: $r->finished_at === null ? null : Pg::ms($r->finished_at),
            currentIndex: (int) $r->current_index,
            currentSentAt: Pg::ms($r->current_sent_at),
            pauseUsedMs: (int) $r->pause_used_ms,
            score: (int) $r->score,
            points: (int) $r->points,
        );
    }

    private static function answer(object $r): EntryAnswerRow
    {
        return new EntryAnswerRow(
            entryId: $r->entry_id,
            qIndex: (int) $r->q_index,
            questionId: $r->question_id,
            choice: $r->choice === null ? null : (int) $r->choice,
            correct: Pg::bool($r->correct),
            points: (int) $r->points,
            sentAt: Pg::ms($r->sent_at),
            answeredAt: Pg::ms($r->answered_at),
        );
    }
}
