<?php

namespace App\Modules\Today;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Today\Services\BattleLocator;
use App\Modules\Today\Services\TodayService;

final class TodayServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        TodayService::class => TodayService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(BattleLocator::class, fn ($app) => $app->make(TodayService::class));
    }

    public function boot(): void
    {
        $this->routes(__DIR__.'/routes.php');
    }
}
