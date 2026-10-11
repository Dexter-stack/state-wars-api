<?php

namespace App\Modules\Stats;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Stats\Repositories\Contracts\StatsRepository;
use App\Modules\Stats\Repositories\PgStatsRepository;
use App\Modules\Stats\Services\StatsService;

final class StatsServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        StatsRepository::class => PgStatsRepository::class,
        StatsService::class => StatsService::class,
    ];

    public function boot(): void
    {
        if (is_file(__DIR__.'/routes.php')) {
            $this->routes(__DIR__.'/routes.php');
        }
    }
}
