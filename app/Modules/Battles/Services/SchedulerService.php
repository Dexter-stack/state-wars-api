<?php

namespace App\Modules\Battles\Services;

use App\Core\Config\GameConfig;
use App\Core\Errors;
use App\Core\WatTime;
use App\Modules\Battles\Repositories\Contracts\BattlesRepository;
use App\Modules\Battles\Support\BattleView;
use App\Modules\Catalog\Catalog;
use App\Modules\Leaderboard\Services\LeaderboardService;
use App\Modules\Questions\Repositories\Contracts\QuestionsRepository;
use Throwable;

/** Creates each day's battles. Run by the scheduler shortly after midnight; admins can also schedule by hand. */
final class SchedulerService
{
    public const QUESTIONS_PER_BATTLE = 10;

    private const NO_REPEAT_DAYS = 60;

    public function __construct(
        private readonly BattlesRepository $battles,
        private readonly QuestionsRepository $questions,
        private readonly LeaderboardService $leaderboard,
        private readonly GameConfig $cfg,
    ) {}

    /** @param list<string>|null $questionIds */
    public function schedule(string $date, string $kind, ?string $subject, ?array $questionIds = null): BattleView
    {
        if ($kind === 'league' && (! $subject || ! Catalog::isSubject($subject))) {
            throw Errors::badRequest('A league battle needs a valid subject.');
        }
        if ($kind === 'daily' && $subject) {
            throw Errors::badRequest("The daily battle mixes subjects; don't set one.");
        }

        $existing = $this->battles->findSlot($date, $kind, $subject);
        if ($existing) {
            return $existing;
        }

        $ids = $questionIds;
        if ($ids !== null) {
            $found = $this->questions->getMany($ids);
            $allApproved = array_reduce($found, fn ($ok, $q) => $ok && $q->status === 'approved', true);
            if (count($found) !== count($ids) || ! $allApproved) {
                throw Errors::badRequest('Every question must exist and be approved.');
            }
        } else {
            $fresh = $this->questions->pickFresh(self::QUESTIONS_PER_BATTLE, WatTime::addDays($date, -self::NO_REPEAT_DAYS), $subject);
            if (count($fresh) < self::QUESTIONS_PER_BATTLE) {
                $for = $subject ? " for {$subject}" : '';
                throw Errors::conflict('not_enough_questions', 'Only '.count($fresh)." fresh approved questions{$for}. Approve more first.");
            }
            $ids = array_map(fn ($q) => $q->id, $fresh);
        }

        $t = WatTime::battleTimes($date, $this->cfg->battleHourWat, $this->cfg->battleWindowMin);

        return $this->battles->create(
            $date, $kind, $subject, WatTime::dayNumber($date, $this->cfg->launchDateWat),
            $t['opensAt'], $t['closesAt'], $ids, $this->uprisingStatesFor($date, $kind, $subject),
        );
    }

    /** On Fridays, the bottom states from the previous night's battle get the Uprising boost. @return list<string> */
    private function uprisingStatesFor(string $date, string $kind, ?string $subject): array
    {
        if (WatTime::watWeekday(WatTime::watMidnight($date) + 12 * WatTime::HOUR_MS) !== 5) {
            return [];
        }
        $prev = $this->battles->findSlot(WatTime::addDays($date, -1), $kind, $subject);

        return $prev ? $this->leaderboard->bottomStates($prev) : [];
    }

    /**
     * Daily battle plus any league that has enough fresh questions. Failures for one league don't block the rest.
     *
     * @return array{created: list<string>, skipped: list<string>}
     */
    public function prepareDay(string $date): array
    {
        $created = [];
        $skipped = [];
        try {
            $this->schedule($date, 'daily', null);
            $created[] = 'daily';
        } catch (Throwable $e) {
            $skipped[] = 'daily: '.$e->getMessage();
        }
        foreach (Catalog::leagueSubjects() as $s) {
            try {
                $this->schedule($date, 'league', $s['id']);
                $created[] = $s['id'];
            } catch (Throwable $e) {
                $skipped[] = "{$s['id']}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
