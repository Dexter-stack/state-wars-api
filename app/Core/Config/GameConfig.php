<?php

namespace App\Core\Config;

/** Game rules, tunable without code changes. Built once from config/statewars.php and injected into services. */
final readonly class GameConfig
{
    public function __construct(
        public int $questionTimeLimitSec = 60,
        public int $answerGraceMs = 2500,
        public int $pauseBudgetMs = 30_000,
        public int $minResumeMs = 10_000,
        public int $battleHourWat = 20,
        public int $battleWindowMin = 15,
        public int $minPlayersRanked = 200,
        public float $uprisingMultiplier = 2,
        public int $uprisingCount = 10,
        public int $stateChangeDays = 30,
        public string $launchDateWat = '2026-10-01',
        public bool $devAlwaysOpen = false,
        public string $publicWebUrl = 'http://localhost:5173',
    ) {}

    /** @param array<string, mixed> $c config('statewars') */
    public static function fromConfig(array $c): self
    {
        return new self(
            questionTimeLimitSec: (int) $c['question_time_limit_sec'],
            answerGraceMs: (int) $c['answer_grace_ms'],
            pauseBudgetMs: (int) $c['pause_budget_ms'],
            minResumeMs: (int) $c['min_resume_ms'],
            battleHourWat: (int) $c['battle_hour_wat'],
            battleWindowMin: (int) $c['battle_window_min'],
            minPlayersRanked: (int) $c['min_players_ranked'],
            uprisingMultiplier: (float) $c['uprising_multiplier'],
            uprisingCount: (int) $c['uprising_count'],
            stateChangeDays: (int) $c['state_change_days'],
            launchDateWat: (string) $c['launch_date_wat'],
            devAlwaysOpen: (bool) $c['dev_always_open'],
            publicWebUrl: (string) $c['public_web_url'],
        );
    }

    public function limitMs(): int
    {
        return $this->questionTimeLimitSec * 1000;
    }
}
