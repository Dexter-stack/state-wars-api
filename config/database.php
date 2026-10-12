<?php

// PostgreSQL 16 and Redis. Connection strings use the Node API's variable names (DATABASE_URL, REDIS_URL).

return [

    'default' => 'pgsql',

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            // Tests set DB_TEST_DATABASE (phpunit.xml): same server and credentials, separate database.
            'url' => (static function (?string $url, ?string $testDb): ?string {
                if (! $url || ! $testDb) {
                    return $url;
                }
                return preg_replace('#^([^?]*/)[^/?]*(\?.*)?$#', '${1}'.$testDb.'${2}', $url) ?: $url;
            })(env('DATABASE_URL', env('DB_URL')), env('DB_TEST_DATABASE')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            // DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD work in place of DATABASE_URL (the URL wins if both are set)
            'database' => env('DB_TEST_DATABASE', env('DB_DATABASE', 'statewars')),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // timestamps come back in UTC; repositories convert them to ms epoch
            'timezone' => 'UTC',
            'options' => [
                PDO::ATTR_TIMEOUT => 5, // connect timeout, like connectionTimeoutMillis: 5000
            ],
            // no request should hold a connection longer than this (statement_timeout: 5000 in the Node pool);
            // applied on connect by AppServiceProvider
            'statement_timeout_ms' => 5000,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        // phpredis where the extension exists (Linux production); predis (pure PHP) otherwise (Windows dev).
        'client' => env('REDIS_CLIENT', extension_loaded('redis') ? 'phpredis' : 'predis'),

        'options' => [
            'cluster' => 'redis',
            // No prefix: keys are the Node API's (lb:{battle}:sum, rv:…, c:…), so both can share one Redis.
            'prefix' => env('REDIS_PREFIX', ''),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
            'database' => env('REDIS_DB', '0'),
            'read_timeout' => 5,
            'timeout' => 5,
            'max_retries' => 3,
        ],

        // cache, locks and rate limits
        'cache' => [
            'url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
            'database' => env('REDIS_DB', '0'),
            'read_timeout' => 5,
            'timeout' => 5,
            'max_retries' => 3,
        ],

        // queue (Horizon) gets no read timeout: workers block on BRPOP
        'queue' => [
            'url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
            'database' => env('REDIS_DB', '0'),
            'read_write_timeout' => -1,
        ],
    ],

];
