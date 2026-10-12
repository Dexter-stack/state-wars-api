<?php

namespace App\Modules\Battles\Services;

use App\Core\Cache\Cache;
use App\Core\Clock\Clock;
use App\Core\Config\GameConfig;
use App\Core\Errors;
use App\Core\JsMath;
use App\Core\Random;
use App\Modules\Battles\Repositories\Contracts\BattlesRepository;
use App\Modules\Battles\Repositories\Contracts\EntriesRepository;
use App\Modules\Battles\Repositories\EntryAnswerRow;
use App\Modules\Battles\Repositories\EntryRow;
use App\Modules\Battles\Stores\Contracts\AnswerStatsStore;
use App\Modules\Battles\Support\BattleView;
use App\Modules\Battles\Support\Scoring;
use App\Modules\Catalog\Catalog;
use App\Modules\Leaderboard\Services\LeaderboardService;
use App\Modules\Questions\Repositories\Contracts\QuestionsRepository;
use App\Modules\Questions\Repositories\QuestionRow;
use App\Modules\Stats\Services\StatsService;
use App\Modules\Users\Services\UsersService;
use App\Shared\Contracts\AnswerResult;
use App\Shared\Contracts\BattleResult;
use App\Shared\Contracts\BattleStart;
use App\Shared\Contracts\PublicQuestion;
use App\Shared\Contracts\Resume;
use App\Shared\Contracts\Reveal;
use RuntimeException;
use Throwable;

/**
 * The battle engine. Rules it guarantees:
 *  - Questions are sent one at a time; the correct answer only leaves the server after the player has answered.
 *  - Time is measured on the server clock from when the question was sent.
 *  - Each player gets their own question order and option order (deterministic from the entry's seed),
 *    so answers leaked into a WhatsApp group don't map to the same letters.
 *  - Every write is idempotent: retries on bad networks never score twice.
 *
 * Octane: a singleton per worker. Its only state is $questionSets, a bounded cache of immutable data.
 */
final class BattlesService
{
    private const QUESTION_SETS_MAX = 64;

    /** Questions are immutable once a battle is scheduled, so each worker keeps them in memory. @var array<string, list<QuestionRow>> */
    private array $questionSets = [];

    public function __construct(
        private readonly BattlesRepository $battles,
        private readonly EntriesRepository $entries,
        private readonly QuestionsRepository $questions,
        private readonly AnswerStatsStore $answerStats,
        private readonly LeaderboardService $leaderboard,
        private readonly UsersService $users,
        private readonly StatsService $stats,
        private readonly Cache $cache,
        private readonly Clock $clock,
        private readonly GameConfig $cfg,
    ) {}

    private function now(): int
    {
        return $this->clock->nowMs();
    }

    private function finalizeAtOf(BattleView $b): int
    {
        return $b->finalizeAt($this->cfg->questionTimeLimitSec, $this->cfg->answerGraceMs, $this->cfg->pauseBudgetMs);
    }

    // ---------- lookups ----------

    public function battleById(string $id): BattleView
    {
        $v = $this->cache->getOrSet("battle:{$id}", 300, function () use ($id) {
            $b = $this->battles->findById($id);
            if (! $b) {
                throw Errors::notFound('Battle');
            }

            return $b->toArray();
        });

        return BattleView::fromArray($v);
    }

    /** The battle accepting players right now (cached 10s; time window re-checked on every call). */
    public function openBattle(string $kind, ?string $subject): ?BattleView
    {
        $anyTime = $this->cfg->devAlwaysOpen;
        $key = "open:{$kind}:".($subject ?? '').':'.($anyTime ? 'true' : 'false');
        $v = $this->cache->getOrSet($key, 10, fn () => $this->battles->findOpen($kind, $subject, $this->now(), $anyTime)?->toArray());
        if (! $v) {
            return null;
        }
        $b = BattleView::fromArray($v);
        if (! $anyTime && ($this->now() < $b->opensAt || $this->now() >= $b->closesAt)) {
            return null;
        }

        return $b;
    }

    /** @return list<QuestionRow> */
    private function questionsFor(BattleView $b): array
    {
        if (isset($this->questionSets[$b->id])) {
            return $this->questionSets[$b->id];
        }
        $qs = $this->questions->getMany($b->questionIds);
        if (count($qs) !== count($b->questionIds)) {
            throw new RuntimeException("Battle {$b->id} references missing questions");
        }
        $this->questionSets[$b->id] = $qs;
        if (count($this->questionSets) > self::QUESTION_SETS_MAX) {
            unset($this->questionSets[array_key_first($this->questionSets)]);
        }

        return $qs;
    }

    private function ownedEntry(string $userId, string $entryId): EntryRow
    {
        $e = $this->entries->findById($entryId);
        if (! $e || $e->userId !== $userId) {
            throw Errors::notFound('Battle entry'); // don't reveal other players' entries exist
        }

        return $e;
    }

    /** @param list<QuestionRow> $qs */
    private function present(array $qs, int $seed, int $index): PublicQuestion
    {
        $q = $qs[Scoring::questionOrder($seed, count($qs))[$index]];
        $order = Scoring::optionOrder($seed, $index);

        return new PublicQuestion(
            index: $index,
            subject: Catalog::subjectName($q->subject),
            text: $q->text,
            highlight: $q->highlight ?: null,
            options: array_map(fn ($k) => $q->options[$k], $order),
            timeLimitSec: $this->cfg->questionTimeLimitSec,
        );
    }

    private function remaining(EntryRow $e): int
    {
        return max(0, $this->cfg->limitMs() - ($this->now() - $e->currentSentAt));
    }

    // ---------- play ----------

    public function start(string $userId, ?string $league): BattleStart
    {
        $b = $this->openBattle($league ? 'league' : 'daily', $league);
        if (! $b) {
            throw Errors::tooEarly("This battle isn't open right now. Battles open at 8pm.");
        }
        $existing = $this->entries->findForUser($b->id, $userId);
        if ($existing?->finishedAt !== null) {
            throw Errors::conflict('already_played', "You've already played this battle. Come back tomorrow.");
        }

        $user = $this->users->get($userId);
        $e = $existing ?? $this->entries->create($b->id, $userId, $user->stateCode, Random::randomSeed(), $this->now());
        $qs = $this->questionsFor($b);
        if ($e->currentIndex >= count($qs)) {
            throw Errors::conflict('already_answered', "You've answered every question. Finish to see your result.");
        }

        return new BattleStart($e->id, count($qs), $this->present($qs, $e->seed, $e->currentIndex), $this->remaining($e));
    }

    /**
     * Where the player is now. Used after reconnecting. If the current question's time ran out while they were away,
     * the deadline is pushed back from the pause budget (30s per battle by default), so a dropped connection isn't fatal.
     */
    public function resume(string $userId, string $entryId): Resume
    {
        $e = $this->ownedEntry($userId, $entryId);
        $b = $this->battleById($e->battleId);
        $qs = $this->questionsFor($b);
        $answered = array_map(fn (EntryAnswerRow $a) => $a->correct, $this->entries->listAnswers($e->id));
        if ($e->finishedAt !== null || $e->currentIndex >= count($qs)) {
            return new Resume($e->id, count($qs), $answered, null, 0, $e->finishedAt !== null);
        }
        $left = $this->remaining($e);
        $budget = $this->cfg->pauseBudgetMs - $e->pauseUsedMs;
        if ($left < $this->cfg->minResumeMs && $budget > 0) {
            $lateBy = $this->now() - $e->currentSentAt - $this->cfg->limitMs() + $this->cfg->minResumeMs;
            $shift = min($budget, max(0, $lateBy));
            if ($shift > 0 && $this->entries->extendCurrent($e->id, $e->currentIndex, $shift)) {
                $e = $this->entries->findById($e->id);
            }
        }

        return new Resume($e->id, count($qs), $answered, $this->present($qs, $e->seed, $e->currentIndex), $this->remaining($e), false);
    }

    public function answer(string $userId, string $entryId, int $index, ?int $displayChoice): AnswerResult
    {
        $e = $this->ownedEntry($userId, $entryId);
        if ($e->finishedAt !== null) {
            throw Errors::conflict('finished', 'This battle is finished.');
        }
        $b = $this->battleById($e->battleId);
        $qs = $this->questionsFor($b);
        if ($index < 0 || $index >= count($qs)) {
            throw Errors::badRequest("That question doesn't exist.");
        }

        if ($index < $e->currentIndex) {
            return $this->replay($qs, $e, $index); // retry of an answer we already have
        }
        if ($index > $e->currentIndex) {
            throw Errors::conflict('out_of_order', 'Answer the current question first.');
        }

        $q = $qs[Scoring::questionOrder($e->seed, count($qs))[$index]];
        $order = Scoring::optionOrder($e->seed, $index);
        $canonical = $displayChoice === null ? null : Scoring::toCanonical($order, $displayChoice);
        $sentAt = $e->currentSentAt;
        $answeredAt = $this->now();
        $s = Scoring::scoreAnswer($canonical, $q->answer, $answeredAt - $sentAt, $this->cfg->limitMs(), $this->cfg->answerGraceMs);

        $stored = $this->entries->recordAnswer(new EntryAnswerRow(
            entryId: $e->id, qIndex: $index, questionId: $q->id, choice: $s['inTime'] ? $canonical : null,
            correct: $s['correct'], points: $s['points'], sentAt: $sentAt, answeredAt: $answeredAt,
        ));
        if (! $stored) {
            return $this->replay($qs, $e, $index); // a duplicate request won the race; return what it stored
        }

        // reveal stats must never slow down or fail an answer
        try {
            $this->answerStats->record($b->id, $q->id, $s['inTime'] ? $canonical : null);
        } catch (Throwable) {
        }

        return new AnswerResult(
            correct: $s['correct'],
            correctIndex: Scoring::toDisplay($order, $q->answer),
            points: $s['points'],
            next: $index + 1 < count($qs) ? $this->present($qs, $e->seed, $index + 1) : null,
        );
    }

    /** @param list<QuestionRow> $qs */
    private function replay(array $qs, EntryRow $e, int $index): AnswerResult
    {
        $a = $this->entries->getAnswer($e->id, $index);
        if (! $a) {
            throw Errors::conflict('out_of_order', 'Answer the current question first.');
        }
        $q = $qs[Scoring::questionOrder($e->seed, count($qs))[$index]];

        return new AnswerResult(
            correct: $a->correct,
            correctIndex: Scoring::toDisplay(Scoring::optionOrder($e->seed, $index), $q->answer),
            points: $a->points,
            next: $index + 1 < count($qs) ? $this->present($qs, $e->seed, $index + 1) : null,
        );
    }

    /** Locks in the result. Leaderboard, streak and badges update exactly once, however many times this is called. */
    public function finish(string $userId, string $entryId): BattleResult
    {
        $e = $this->ownedEntry($userId, $entryId);
        $b = $this->battleById($e->battleId);
        $qs = $this->questionsFor($b);
        $stillPlaying = $this->now() < $this->finalizeAtOf($b);
        if ($e->finishedAt === null && $e->currentIndex < count($qs) && $stillPlaying && ! $this->cfg->devAlwaysOpen) {
            throw Errors::conflict('not_done', 'Answer all the questions first.');
        }
        $this->settle($b, $e, count($qs));

        return $this->result($userId, $b, $this->entries->findById($e->id), count($qs));
    }

    /** The result of an entry that is already finished (by the player or by the close job). Read-only. */
    public function finishedResult(string $userId, string $entryId): BattleResult
    {
        $e = $this->ownedEntry($userId, $entryId);
        if ($e->finishedAt === null) {
            throw Errors::conflict('not_finished', "This battle isn't finished yet.");
        }
        $b = $this->battleById($e->battleId);

        return $this->result($userId, $b, $e, count($b->questionIds));
    }

    /** Marks an entry finished and applies its side effects once. Shared by finish() and the close job. */
    private function settle(BattleView $b, EntryRow $e, int $total): void
    {
        if (! $this->entries->finish($e->id, $this->now())) {
            return;
        }
        $this->leaderboard->recordFinish($b, $e->stateCode, $e->score);
        $r = $this->users->applyBattleResult($e->userId, $b->date, $e->points);
        $this->stats->evaluateBadges($e->userId, $e->score, $total, $r['streak'], in_array($e->stateCode, $b->uprisingStates, true));
    }

    private function result(string $userId, BattleView $b, EntryRow $e, int $total): BattleResult
    {
        $byIndex = [];
        foreach ($this->entries->listAnswers($e->id) as $a) {
            $byIndex[$a->qIndex] = $a->correct;
        }
        $snap = $this->leaderboard->stateSnapshot($b, $e->stateCode, true);
        $banter = $this->leaderboard->banterFor($b, $e->stateCode);
        $pct = $this->leaderboard->percentile($b, $e->score);
        $me = $this->users->summary($userId);

        return new BattleResult(
            day: $b->dayNumber,
            score: $e->score,
            total: $total,
            answers: array_map(fn ($i) => $byIndex[$i] ?? false, range(0, $total - 1)),
            points: $e->points,
            stateCode: $e->stateCode,
            stateRank: (int) $snap['rank'],
            stateMove: (int) $snap['move'],
            banter: $banter,
            streak: $me->streak,
            rank: $me->rank,
            percentile: $pct,
        );
    }

    /** @return array{battleId: string, day: int, total: int}|null */
    public function latest(string $userId): ?array
    {
        $e = $this->entries->latestFinished($userId);
        if (! $e) {
            return null;
        }
        $b = $this->battleById($e->battleId);

        return ['battleId' => $e->id, 'day' => $b->dayNumber, 'total' => count($b->questionIds)];
    }

    /** "How Nigeria answered". Locked until every player has finished, so answers can't leak mid-battle. */
    public function reveal(string $userId, string $entryId, int $index): Reveal
    {
        $e = $this->ownedEntry($userId, $entryId);
        $b = $this->battleById($e->battleId);
        $unlockAt = $this->finalizeAtOf($b);
        if (! $this->cfg->devAlwaysOpen && $this->now() < $unlockAt) {
            $t = gmdate('H:i', intdiv($unlockAt + 3_600_000, 1000)); // WAT
            throw Errors::tooEarly("Answers unlock at {$t} once everyone has finished.");
        }
        $qs = $this->questionsFor($b);
        if ($index < 0 || $index >= count($qs)) {
            throw Errors::notFound('Question');
        }
        $q = $qs[Scoring::questionOrder($e->seed, count($qs))[$index]];
        $order = Scoring::optionOrder($e->seed, $index);
        $dist = $this->answerStats->distribution($b->id, $q->id);
        $mine = $this->entries->getAnswer($e->id, $index);
        $answered = array_sum($dist['counts']);
        $pct = fn (int $k) => $answered ? JsMath::round(($dist['counts'][$k] / $answered) * 100) : 0;

        return new Reveal(
            index: $index,
            total: count($qs),
            subject: Catalog::subjectName($q->subject),
            text: $q->text,
            options: array_map(fn ($k) => ['text' => $q->options[$k], 'pct' => $pct($k)], $order),
            correctIndex: Scoring::toDisplay($order, $q->answer),
            yourChoice: $mine?->choice === null ? null : Scoring::toDisplay($order, $mine->choice),
            explanation: $q->explanation,
            funStat: $q->funHeadline ? ['headline' => $q->funHeadline, 'banter' => $q->funBanter ?? ''] : null,
        );
    }

    // ---------- jobs ----------

    /** Close job: finish every abandoned entry, then finalize the table. Safe to run repeatedly. @return array{settled: int} */
    public function closeBattle(string $battleId, int $batchSize = 500): array
    {
        $b = $this->battleById($battleId);
        $qs = $this->questionsFor($b);
        $settled = 0;
        while (true) {
            $batch = $this->entries->listUnfinished($b->id, $batchSize);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $e) {
                $this->settle($b, $e, count($qs));
                $settled++;
            }
        }
        $this->leaderboard->finalize($b);
        $this->battles->markFinalized($b->id);
        $this->cache->del("battle:{$b->id}");

        return ['settled' => $settled];
    }

    /** Battles whose last player has had time to finish. @return list<BattleView> */
    public function dueForClose(): array
    {
        $rows = $this->battles->listScheduledClosedBefore($this->now());

        return array_values(array_filter($rows, fn (BattleView $b) => $this->now() >= $this->finalizeAtOf($b)));
    }
}
