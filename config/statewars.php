<?php

// Game rules and limits. Same environment variable names as the Node API's .env.example.
// Validated at boot by App\Core\Config\EnvValidator (bad values stop the process, like config/env.ts).

return [
    'node_env' => env('NODE_ENV'),

    'question_time_limit_sec' => env('QUESTION_TIME_LIMIT_SEC', 60),
    'answer_grace_ms' => env('ANSWER_GRACE_MS', 2500),
    'pause_budget_ms' => env('PAUSE_BUDGET_MS', 30000),
    'min_resume_ms' => env('MIN_RESUME_MS', 10000),
    'battle_hour_wat' => env('BATTLE_HOUR_WAT', 20),
    'battle_window_min' => env('BATTLE_WINDOW_MIN', 15),
    'min_players_ranked' => env('MIN_PLAYERS_RANKED', 200),
    'uprising_multiplier' => env('UPRISING_MULTIPLIER', 2),
    'uprising_count' => env('UPRISING_COUNT', 10),
    'state_change_days' => env('STATE_CHANGE_DAYS', 30),
    'launch_date_wat' => env('LAUNCH_DATE_WAT', '2026-10-01'),

    // development only: battles playable at any time, answers revealed straight away
    'dev_always_open' => env('DEV_ALWAYS_OPEN', false),

    'cors_origins' => env('CORS_ORIGINS', 'http://localhost:5173'),
    'public_web_url' => env('PUBLIC_WEB_URL', 'http://localhost:5173'),

    // rate limits
    'rate_global_per_min' => env('RATE_GLOBAL_PER_MIN', 240),
    'rate_auth_per_hour' => env('RATE_AUTH_PER_HOUR', 20),
    'rate_answers_per_min' => 40, // fixed, as in the Node API

    // HTTP
    'body_limit_bytes' => 16 * 1024, // the biggest legit body is an admin question; nothing needs more
];
