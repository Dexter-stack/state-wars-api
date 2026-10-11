<?php

use App\Core\Cache\TwoLevelCache;
use Illuminate\Support\Facades\Redis;

it('loads once and shares the value through Redis between workers', function () {
    $loads = 0;
    $load = function () use (&$loads) {
        $loads++;

        return ['rows' => [1, 2, 3]];
    };
    $workerA = new TwoLevelCache(Redis::connection());
    $workerB = new TwoLevelCache(Redis::connection()); // another Octane worker: empty local level

    expect($workerA->getOrSet('t', 5, $load))->toBe(['rows' => [1, 2, 3]])
        ->and($workerA->getOrSet('t', 5, $load))->toBe(['rows' => [1, 2, 3]])
        ->and($workerB->getOrSet('t', 5, $load))->toBe(['rows' => [1, 2, 3]]);
    expect($loads)->toBe(1);
    expect(Redis::connection()->ttl('c:t'))->toBeGreaterThan(0)->toBeLessThanOrEqual(5);
});

it('caches null like the Node cache and forgets on del', function () {
    $c = new TwoLevelCache(Redis::connection());
    $loads = 0;
    $c->getOrSet('n', 60, function () use (&$loads) {
        $loads++;

        return null;
    });
    $c->getOrSet('n', 60, function () use (&$loads) {
        $loads++;

        return null;
    });
    expect($loads)->toBe(1);
    $c->del('n');
    expect(Redis::connection()->exists('c:n'))->toBe(0);
    expect($c->getOrSet('n', 60, fn () => 'fresh'))->toBe('fresh');
});

it('releases its single-flight lock and serves from the database when Redis has nothing', function () {
    $c = new TwoLevelCache(Redis::connection());
    expect($c->getOrSet('x', 5, fn () => 42))->toBe(42);
    expect(Redis::connection()->exists('c:sf:x'))->toBe(0);
});
