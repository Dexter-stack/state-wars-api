<?php

namespace App\Modules\Challenges;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Challenges\Repositories\Contracts\ChallengesRepository;
use App\Modules\Challenges\Repositories\PgChallengesRepository;
use App\Modules\Challenges\Services\ChallengesService;

final class ChallengesServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        ChallengesRepository::class => PgChallengesRepository::class,
        ChallengesService::class => ChallengesService::class,
    ];

    public function boot(): void
    {
        $this->routes(__DIR__.'/routes.php');
    }
}
