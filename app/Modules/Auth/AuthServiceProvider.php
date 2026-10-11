<?php

namespace App\Modules\Auth;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Auth\Http\Middleware\Authenticate;
use App\Modules\Auth\Http\Middleware\RequireAdmin;
use App\Modules\Auth\Repositories\Contracts\SessionsRepository;
use App\Modules\Auth\Repositories\SanctumSessionsRepository;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Routing\Router;

final class AuthServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        SessionsRepository::class => SanctumSessionsRepository::class,
        AuthService::class => AuthService::class,
    ];

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('auth.player', Authenticate::class);
        $router->aliasMiddleware('auth.admin', RequireAdmin::class);
        $this->routes(__DIR__.'/routes.php');
    }
}
