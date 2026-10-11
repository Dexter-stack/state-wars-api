<?php

namespace App\Core\Config;

use RuntimeException;

/**
 * Port of the Node config/env.ts schema. Runs at boot in every process (API, queue worker, scheduler, artisan),
 * so a bad value stops the process instead of misbehaving at 8pm.
 */
final class EnvValidator
{
    private const INTS = [
        'question_time_limit_sec' => 'QUESTION_TIME_LIMIT_SEC',
        'answer_grace_ms' => 'ANSWER_GRACE_MS',
        'pause_budget_ms' => 'PAUSE_BUDGET_MS',
        'min_resume_ms' => 'MIN_RESUME_MS',
        'battle_window_min' => 'BATTLE_WINDOW_MIN',
        'min_players_ranked' => 'MIN_PLAYERS_RANKED',
        'uprising_count' => 'UPRISING_COUNT',
        'state_change_days' => 'STATE_CHANGE_DAYS',
        'rate_global_per_min' => 'RATE_GLOBAL_PER_MIN',
        'rate_auth_per_hour' => 'RATE_AUTH_PER_HOUR',
    ];

    /**
     * @param  array<string, mixed>  $c  config('statewars')
     * @return array<string, mixed> the config with values cast to their types
     */
    public static function validate(array $c, string $appEnv): array
    {
        $issues = [];

        foreach (self::INTS as $key => $name) {
            if (! self::isInt($c[$key] ?? null)) {
                $issues[] = "  {$name}: Expected an integer, received ".var_export($c[$key] ?? null, true);
            } else {
                $c[$key] = (int) $c[$key];
            }
        }

        $hour = $c['battle_hour_wat'] ?? null;
        if (! self::isInt($hour) || (int) $hour < 0 || (int) $hour > 23) {
            $issues[] = '  BATTLE_HOUR_WAT: Expected an integer from 0 to 23';
        } else {
            $c['battle_hour_wat'] = (int) $hour;
        }

        if (! is_numeric($c['uprising_multiplier'] ?? null)) {
            $issues[] = '  UPRISING_MULTIPLIER: Expected a number';
        } else {
            $c['uprising_multiplier'] = (float) $c['uprising_multiplier'];
        }

        if (! is_string($c['launch_date_wat'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['launch_date_wat'])) {
            $issues[] = '  LAUNCH_DATE_WAT: Expected YYYY-MM-DD';
        }

        // env() turns "true"/"false" into booleans; anything else is a typo we refuse (zod enum ["true","false"])
        $dev = $c['dev_always_open'] ?? false;
        if (! is_bool($dev)) {
            $issues[] = '  DEV_ALWAYS_OPEN: Expected "true" or "false", received '.var_export($dev, true);
        }

        if (! filter_var($c['public_web_url'] ?? null, FILTER_VALIDATE_URL)) {
            $issues[] = '  PUBLIC_WEB_URL: Invalid url';
        }

        $nodeEnv = $c['node_env'] ?? null;
        if ($nodeEnv !== null && ! in_array($nodeEnv, ['development', 'test', 'production'], true)) {
            $issues[] = "  NODE_ENV: Expected 'development' | 'test' | 'production'";
        }

        if ($issues !== []) {
            throw new RuntimeException("Invalid environment variables:\n".implode("\n", $issues));
        }

        if ($dev === true && ($appEnv === 'production' || $nodeEnv === 'production')) {
            throw new RuntimeException('DEV_ALWAYS_OPEN must be false in production.');
        }

        return $c;
    }

    private static function isInt(mixed $v): bool
    {
        return is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)) === 1);
    }
}
