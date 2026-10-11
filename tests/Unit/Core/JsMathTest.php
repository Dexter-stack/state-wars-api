<?php

use App\Core\JsMath;
use App\Modules\Catalog\Catalog;

it('rounds halves up like Math.round, including negatives', function () {
    expect(JsMath::round(2.5))->toBe(3)
        ->and(JsMath::round(-2.5))->toBe(-2)
        ->and(JsMath::round(-2.6))->toBe(-3)
        ->and(JsMath::round1(-0.25))->toBe(-0.2)
        ->and(JsMath::round2(6.555))->toBe(6.56);
});

it('formats like Number.prototype.toFixed', function () {
    expect(JsMath::toFixed(6.25, 1))->toBe('6.3')   // exact tie: away from zero
        ->and(JsMath::toFixed(1.005, 2))->toBe('1.00') // 1.005 is 1.00499999… in binary
        ->and(JsMath::toFixed(0.05, 1))->toBe('0.1')
        ->and(JsMath::toFixed(7, 1))->toBe('7.0')
        ->and(JsMath::toFixed(9.96, 1))->toBe('10.0')
        ->and(JsMath::toFixed(-1.25, 1))->toBe('-1.3')
        ->and(JsMath::toFixed(0, 1))->toBe('0.0');
});

it('writes ordinals like the Node catalog', function () {
    expect(array_map(fn ($n) => Catalog::ordinal($n), [1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 37, 100, 101, 111]))
        ->toBe(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '23rd', '37th', '100th', '101st', '111th']);
});
