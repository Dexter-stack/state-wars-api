<?php

namespace App\Modules\Derbies;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Derbies\Repositories\Contracts\DerbiesRepository;
use App\Modules\Derbies\Repositories\PgDerbiesRepository;

final class DerbiesServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        DerbiesRepository::class => PgDerbiesRepository::class,
    ];

    public function boot(): void
    {
        if (is_file(__DIR__.'/routes.php')) {
            $this->routes(__DIR__.'/routes.php');
        }
    }
}
