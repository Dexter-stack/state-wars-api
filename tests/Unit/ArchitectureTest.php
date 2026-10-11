<?php

// The structure rules from CLAUDE.md, enforced.

$modules = ['Auth', 'Users', 'Battles', 'Leaderboard', 'Derbies', 'Challenges', 'Stats', 'Questions', 'Today'];

foreach ($modules as $m) {
    arch("{$m} services use only interfaces: no facades, no DB, no Redis, no Eloquent, no HTTP")
        ->expect("App\\Modules\\{$m}\\Services")
        ->not->toUse([
            'Illuminate\Support\Facades',
            'Illuminate\Database',
            'Illuminate\Redis',
            'Illuminate\Http',
            "App\\Modules\\{$m}\\Repositories\\Pg",
            'DB', 'Redis', 'Cache',
            'now', 'config', 'env', 'request',
        ]);

    arch("{$m} services never name a Pg/Redis implementation")
        ->expect("App\\Modules\\{$m}\\Services")
        ->not->toUse(['App\Modules\Battles\Repositories\PgBattlesRepository', 'App\Modules\Battles\Repositories\PgEntriesRepository',
            'App\Modules\Users\Repositories\PgUsersRepository', 'App\Modules\Questions\Repositories\PgQuestionsRepository',
            'App\Modules\Leaderboard\Repositories\PgResultsRepository', 'App\Modules\Stats\Repositories\PgStatsRepository',
            'App\Modules\Leaderboard\Stores\RedisLiveScoreStore', 'App\Modules\Battles\Stores\RedisAnswerStatsStore']);

    arch("{$m} controllers do not query")
        ->expect("App\\Modules\\{$m}\\Http\\Controllers")
        ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Redis', 'Illuminate\Database']);
}

arch('core time is never read directly in services')
    ->expect('App\Modules')
    ->not->toUse(['time', 'microtime', 'date']);
