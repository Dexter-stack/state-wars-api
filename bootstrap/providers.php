<?php

return [
    App\Providers\AppServiceProvider::class,
    // one provider per module: binds its interfaces and loads its routes
    App\Modules\Auth\AuthServiceProvider::class,
    App\Modules\Users\UsersServiceProvider::class,
];
