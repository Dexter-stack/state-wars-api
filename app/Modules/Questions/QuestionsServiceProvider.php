<?php

namespace App\Modules\Questions;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Questions\Repositories\Contracts\QuestionsRepository;
use App\Modules\Questions\Repositories\PgQuestionsRepository;

final class QuestionsServiceProvider extends ModuleServiceProvider
{
    public array $singletons = [
        QuestionsRepository::class => PgQuestionsRepository::class,
    ];

    public function boot(): void
    {
        if (is_file(__DIR__.'/routes.php')) {
            $this->routes(__DIR__.'/routes.php');
        }
    }
}
