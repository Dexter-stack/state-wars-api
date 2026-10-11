<?php

return [
    App\Providers\AppServiceProvider::class,
    // one provider per module: binds its interfaces and loads its routes
    App\Modules\Auth\AuthServiceProvider::class,
    App\Modules\Users\UsersServiceProvider::class,
    App\Modules\Battles\BattlesServiceProvider::class,
    App\Modules\Leaderboard\LeaderboardServiceProvider::class,
    App\Modules\Today\TodayServiceProvider::class,
    App\Modules\Derbies\DerbiesServiceProvider::class,
    App\Modules\Challenges\ChallengesServiceProvider::class,
    App\Modules\Stats\StatsServiceProvider::class,
    App\Modules\Questions\QuestionsServiceProvider::class,
];
