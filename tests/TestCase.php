<?php

namespace Tests;

use App\Core\Cache\Cache;
use App\Core\Cache\TwoLevelCache;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Safety: feature tests must never touch the development database.
        $db = (string) \DB::connection()->getDatabaseName();
        if ($db !== 'statewars_test') {
            throw new \RuntimeException("Refusing to run feature tests against database '{$db}'.");
        }

        Redis::connection()->flushdb(); // REDIS_DB=15 in phpunit.xml
        $cache = $this->app->make(Cache::class);
        if ($cache instanceof TwoLevelCache) {
            $cache->flushLocal();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
