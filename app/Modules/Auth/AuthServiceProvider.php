<?php

namespace App\Modules\Auth;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Auth\Http\Middleware\Authenticate;
use App\Modules\Auth\Http\Middleware\RequireAdmin;
use App\Modules\Auth\Repositories\Contracts\CredentialsRepository;
use App\Modules\Auth\Repositories\Contracts\SessionsRepository;
use App\Modules\Auth\Repositories\PgCredentialsRepository;
use App\Modules\Auth\Repositories\SanctumSessionsRepository;
use App\Modules\Auth\Services\AccountService;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Stores\Contracts\CodeStore;
use App\Modules\Auth\Stores\RedisCodeStore;
use App\Modules\Auth\Support\Contracts\CodeMailer;
use App\Modules\Auth\Support\Contracts\PasswordHasher;
use App\Modules\Auth\Support\QueuedCodeMailer;
use App\Modules\Auth\Support\LaravelPasswordHasher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Redis;

final class AuthServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        SessionsRepository::class => SanctumSessionsRepository::class,
        CredentialsRepository::class => PgCredentialsRepository::class,
        PasswordHasher::class => LaravelPasswordHasher::class,
        CodeMailer::class => QueuedCodeMailer::class, // queues SendCodeEmailJob, which sends with LaravelCodeMailer
        AuthService::class => AuthService::class,
        AccountService::class => AccountService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(CodeStore::class, fn () => new RedisCodeStore(Redis::connection('default')));
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('auth.player', Authenticate::class);
        $router->aliasMiddleware('auth.admin', RequireAdmin::class);
        $this->routes(__DIR__.'/routes.php');
    }
}
