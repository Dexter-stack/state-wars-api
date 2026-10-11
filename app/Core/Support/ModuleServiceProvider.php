<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base for app/Modules/<Name>/<Name>ServiceProvider. Each module lists `public array $singletons`
 * (interface => Pg/Redis implementation; Laravel registers them) — the only place that names concrete
 * classes, like the Node container.ts — and loads its routes.php.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    protected function routes(string $file): void
    {
        if (! $this->app->routesAreCached()) {
            Route::group([], $file);
        }
    }
}
