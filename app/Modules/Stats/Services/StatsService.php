<?php

namespace App\Modules\Stats\Services;

use App\Core\Clock\Clock;
use App\Core\WatTime;
use App\Modules\Catalog\Catalog;
use App\Modules\Leaderboard\Repositories\Contracts\ResultsRepository;
use App\Modules\Stats\Repositories\Contracts\StatsRepository;
use App\Modules\Users\Services\UsersService;
use App\Shared\Contracts\StatsPage;

final class StatsService
{
    public const BADGES = [
        ['id' => 'perfect_10', 'name' => 'Perfect 10'],
        ['id' => 'week_warrior', 'name' => 'Week Warrior'],
        ['id' => 'uprising_hero', 'name' => 'Uprising Hero'],
        ['id' => 'streak_30', 'name' => '30-day streak'],
        ['id' => 'first_battle', 'name' => 'First battle'],
    ];

    public function __construct(
        private readonly StatsRepository $stats,
        private readonly UsersService $users,
        private readonly ResultsRepository $results,
        private readonly Clock $clock,
    ) {}

    /** Called once when a player finishes a battle. */
    public function evaluateBadges(string $userId, int $score, int $total, int $streak, bool $uprising): void
    {
        $earned = ['first_battle'];
        if ($score === $total) {
            $earned[] = 'perfect_10';
        }
        if ($streak >= 7) {
            $earned[] = 'week_warrior';
        }
        if ($streak >= 30) {
            $earned[] = 'streak_30';
        }
        if ($uprising && $score >= (int) ceil($total * 0.8)) {
            $earned[] = 'uprising_hero';
        }
        $this->stats->award($userId, $earned);
    }

    public function page(string $userId): StatsPage
    {
        $player = $this->users->summary($userId);
        $history = $this->stats->recentScores($userId, 14);
        $accuracy = array_map(
            fn ($a) => ['subject' => Catalog::subjectName($a['subject']), 'pct' => (int) $a['pct']],
            $this->stats->accuracyBySubject($userId),
        );
        usort($accuracy, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        $have = array_flip($this->stats->badges($userId));
        $pushed = $this->results->rankChange($player->stateCode, WatTime::watDate($this->clock->nowMs() - 30 * WatTime::DAY_MS));

        return new StatsPage(
            player: $player,
            history: $history,
            accuracy: $accuracy,
            badges: array_map(fn ($b) => ['name' => $b['name'], 'earned' => isset($have[$b['id']])], self::BADGES),
            placesPushed: max(0, $pushed),
        );
    }
}
