<?php

namespace App\Modules\Users;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Users\Repositories\Contracts\UsersRepository;
use App\Modules\Users\Repositories\PgUsersRepository;
use App\Modules\Users\Services\UsersService;

final class UsersServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        UsersRepository::class => PgUsersRepository::class,
        UsersService::class => UsersService::class,
    ];

    public function boot(): void
    {
        $this->routes(__DIR__.'/routes.php');
    }
}
