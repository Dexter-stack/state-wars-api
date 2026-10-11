<?php

use App\Core\Random;

// Golden vectors generated from the Node source (tests/fixtures/make-shuffle-vectors.mjs):
// the same seed must give the same question and option order in both APIs, or players' answers
// recorded by one would be scored wrongly by the other.
$vectors = json_decode(file_get_contents(__DIR__.'/../../fixtures/shuffle-vectors.json'), true);

it('reproduces mulberry32 exactly for 1,000 seeds', function () use ($vectors) {
    foreach ($vectors as $v) {
        $r = Random::prng($v['seed']);
        foreach ($v['prng'] as $expected) {
            expect($r())->toBe((float) $expected);
        }
    }
});

it('reproduces question and option orders exactly', function () use ($vectors) {
    foreach ($vectors as $v) {
        expect(Random::permutation(10, $v['seed']))->toBe($v['questions10'])
            ->and(Random::permutation(7, $v['seed']))->toBe($v['questions7']);
        foreach ($v['options'] as $index => $order) {
            expect(Random::permutation(4, ($v['seed'] + ($index + 1) * 7919) & 0xFFFFFFFF))->toBe($order);
        }
    }
});

it('makes seeds in the Node range', function () {
    for ($i = 0; $i < 100; $i++) {
        expect(Random::randomSeed())->toBeGreaterThanOrEqual(0)->toBeLessThan(2 ** 31);
    }
});
