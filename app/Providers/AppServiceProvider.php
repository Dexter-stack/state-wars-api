<?php

namespace App\Providers;

use App\Core\Cache\Cache;
use App\Core\Cache\TwoLevelCache;
use App\Core\Clock\CarbonClock;
use App\Core\Clock\Clock;
use App\Core\Config\EnvValidator;
use App\Core\Config\GameConfig;
use App\Core\Http\Middleware\JsonBody;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

/**
 * Shared infrastructure: config validation, clock, cache, rate limits.
 * Module providers (app/Modules/<Name>/<Name>ServiceProvider.php) bind each module's interfaces.
 *
 * Octane: everything here is a singleton without request state, safe to keep for the worker's lifetime.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Refuses bad env values and DEV_ALWAYS_OPEN in production before anything else runs (config/env.ts).
        $statewars = EnvValidator::validate((array) config('statewars'), (string) config('app.env'));
        config(['statewars' => $statewars]);

        $this->app->singleton(GameConfig::class, fn () => GameConfig::fromConfig(config('statewars')));
        $this->app->singleton(Clock::class, CarbonClock::class);
        $this->app->singleton(Cache::class, fn () => new TwoLevelCache(Redis::connection('default')));
        $this->app->singleton(JsonBody::class, fn () => new JsonBody((int) config('statewars.body_limit_bytes')));
    }

    public function boot(): void
    {
        // statement_timeout on every new connection (the Node pool sets it per connection too)
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $e) {
            $ms = (int) ($e->connection->getConfig('statement_timeout_ms') ?? 0);
            if ($ms > 0 && $e->connection->getDriverName() === 'pgsql') {
                $e->connection->statement("SET statement_timeout = {$ms}");
            }
        });

        $this->rateLimits();
    }

    /**
     * Port of @fastify/rate-limit: signed-in players are limited per token, everyone else per IP.
     * A route-specific limit REPLACES the global one for that route (as Fastify's route config does).
     */
    private function rateLimits(): void
    {
        $key = static function (Request $r): string {
            $t = self::bearer($r);

            return $t !== null ? 't:'.substr(hash('sha256', $t), 0, 24) : 'ip:'.$r->ip();
        };

        RateLimiter::for('global', fn (Request $r) => Limit::perMinute((int) config('statewars.rate_global_per_min'))->by($key($r)));
        RateLimiter::for('auth', fn (Request $r) => Limit::perHour((int) config('statewars.rate_auth_per_hour'))->by($key($r)));
        RateLimiter::for('answers', fn (Request $r) => Limit::perMinute((int) config('statewars.rate_answers_per_min'))->by($key($r)));
    }

    public static function bearer(Request $r): ?string
    {
        $h = (string) $r->headers->get('Authorization', '');

        return str_starts_with($h, 'Bearer ') ? trim(substr($h, 7)) : null;
    }
}
