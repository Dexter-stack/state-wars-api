<?php

namespace App\Modules\Battles;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Battles\Repositories\Contracts\BattlesRepository;
use App\Modules\Battles\Repositories\Contracts\EntriesRepository;
use App\Modules\Battles\Repositories\PgBattlesRepository;
use App\Modules\Battles\Repositories\PgEntriesRepository;
use App\Modules\Battles\Services\BattlesService;
use App\Modules\Battles\Services\SchedulerService;
use App\Modules\Battles\Stores\Contracts\AnswerStatsStore;
use App\Modules\Battles\Stores\RedisAnswerStatsStore;
use Illuminate\Support\Facades\Redis;

final class BattlesServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        BattlesRepository::class => PgBattlesRepository::class,
        EntriesRepository::class => PgEntriesRepository::class,
        BattlesService::class => BattlesService::class,
        SchedulerService::class => SchedulerService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(AnswerStatsStore::class, fn () => new RedisAnswerStatsStore(Redis::connection('default')));
    }

    public function boot(): void
    {
        $this->routes(__DIR__.'/routes.php');
    }
}
