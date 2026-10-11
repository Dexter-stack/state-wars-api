<?php

namespace App\Modules\Leaderboard;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Leaderboard\Repositories\Contracts\ResultsRepository;
use App\Modules\Leaderboard\Repositories\PgResultsRepository;
use App\Modules\Leaderboard\Services\LeaderboardService;
use App\Modules\Leaderboard\Stores\Contracts\LiveScoreStore;
use App\Modules\Leaderboard\Stores\RedisLiveScoreStore;
use Illuminate\Support\Facades\Redis;

final class LeaderboardServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        ResultsRepository::class => PgResultsRepository::class,
        LeaderboardService::class => LeaderboardService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(LiveScoreStore::class, fn () => new RedisLiveScoreStore(Redis::connection('default')));
    }

    public function boot(): void
    {
        if (is_file(__DIR__.'/routes.php')) {
            $this->routes(__DIR__.'/routes.php');
        }
    }
}
