<?php

namespace App\Modules\Today\Services;

use App\Core\Cache\Cache;
use App\Core\Clock\Clock;
use App\Core\Config\GameConfig;
use App\Core\JsMath;
use App\Core\WatTime;
use App\Modules\Battles\Repositories\Contracts\BattlesRepository;
use App\Modules\Battles\Repositories\Contracts\EntriesRepository;
use App\Modules\Battles\Support\BattleView;
use App\Modules\Catalog\Catalog;
use App\Modules\Derbies\Repositories\Contracts\DerbiesRepository;
use App\Modules\Leaderboard\Repositories\Contracts\ResultsRepository;
use App\Modules\Leaderboard\Services\LeaderboardService;
use App\Modules\Users\Services\UsersService;
use App\Shared\Contracts\Today;

/** Everything the Home screen needs in one call. Port of today.service.ts. */
final class TodayService implements BattleLocator
{
    public function __construct(
        private readonly BattlesRepository $battles,
        private readonly EntriesRepository $entries,
        private readonly LeaderboardService $leaderboard,
        private readonly ResultsRepository $results,
        private readonly DerbiesRepository $derbies,
        private readonly UsersService $users,
        private readonly Cache $cache,
        private readonly Clock $clock,
        private readonly GameConfig $cfg,
    ) {}

    public function todaysBattle(string $kind, ?string $subject): ?BattleView
    {
        $date = WatTime::watDate($this->clock->nowMs());
        $v = $this->cache->getOrSet("slot:{$date}:{$kind}:".($subject ?? ''), 30,
            fn () => $this->battles->findSlot($date, $kind, $subject)?->toArray());

        return $v ? BattleView::fromArray($v) : null;
    }

    public function today(string $userId): Today
    {
        $now = $this->clock->nowMs();
        $date = WatTime::watDate($now);
        $user = $this->users->get($userId);
        $b = $this->todaysBattle('daily', null);

        $times = $b ? ['opensAt' => $b->opensAt, 'closesAt' => $b->closesAt]
            : WatTime::battleTimes($date, $this->cfg->battleHourWat, $this->cfg->battleWindowMin);
        $status = $now < $times['opensAt'] ? 'upcoming' : ($now < $times['closesAt'] ? 'live' : 'closed');
        $next = $status === 'closed'
            ? WatTime::battleTimes(WatTime::addDays($date, 1), $this->cfg->battleHourWat, $this->cfg->battleWindowMin)
            : $times;

        $snap = $b && $now >= $b->opensAt
            ? $this->leaderboard->stateSnapshot($b, $user->stateCode)
            : ['rank' => 0, 'move' => 0, 'average' => 0, 'players' => 0];
        // $b is the main battle of today's WAT date, so this still describes it after it closes, until midnight
        $entry = $b ? $this->entries->findForUser($b->id, $userId) : null;

        return new Today(
            battleId: $b?->id,
            day: $b?->dayNumber ?? WatTime::dayNumber($date, $this->cfg->launchDateWat),
            status: $status,
            startsAt: $status === 'closed' ? $next['opensAt'] : $times['opensAt'],
            endsAt: $status === 'closed' ? $next['closesAt'] : $times['closesAt'],
            myState: $snap,
            derby: $this->derby($date),
            isUprisingDay: WatTime::watWeekday($now) === 5,
            myEntry: $entry ? ['entryId' => $entry->id, 'finished' => $entry->finishedAt !== null] : null,
        );
    }

    /** @return array{home: string, away: string, note: string}|null */
    private function derby(string $date): ?array
    {
        return $this->cache->getOrSet("derby:{$date}", 300, function () use ($date) {
            $d = $this->derbies->forDate($date);
            if (! $d) {
                return null;
            }
            $last = $this->results->headToHead($d['home'], $d['away'], $date, 1)[0] ?? null;
            $note = match (true) {
                $last === null => 'First derby between these two.',
                $last['home'] === $last['away'] => 'Last derby ended level.',
                default => 'Last derby: '.Catalog::stateName($last['home'] > $last['away'] ? $d['home'] : $d['away'])
                    .' won by '.JsMath::toFixed(abs($last['home'] - $last['away']), 1).' points.',
            };

            return ['home' => Catalog::stateName($d['home']), 'away' => Catalog::stateName($d['away']), 'note' => $note];
        });
    }

    /** @return list<array{id: string, name: string, leader: string, myStateRank: int}> contracts.ts Subject[] */
    public function subjects(string $userId): array
    {
        $user = $this->users->get($userId);
        $out = [];
        foreach (Catalog::leagueSubjects() as $s) {
            $b = $this->todaysBattle('league', $s['id']);
            if (! $b || $this->clock->nowMs() < $b->opensAt) {
                $out[] = ['id' => $s['id'], 'name' => $s['name'], 'leader' => 'Not started', 'myStateRank' => 0];

                continue;
            }
            $rows = $this->leaderboard->liveTable($b);
            $leader = 'Not enough players yet';
            $mine = 0;
            foreach ($rows as $r) {
                if ($r['ranked'] && $leader === 'Not enough players yet') {
                    $leader = $r['state'];
                }
                if ($r['stateCode'] === $user->stateCode) {
                    $mine = $r['rank'] ?? 0;
                }
            }
            $out[] = ['id' => $s['id'], 'name' => $s['name'], 'leader' => $leader, 'myStateRank' => $mine];
        }

        return $out;
    }
}
