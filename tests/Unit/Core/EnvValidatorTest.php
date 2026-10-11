<?php

use App\Core\Config\EnvValidator;

function envConfig(array $over = []): array
{
    return array_merge(require __DIR__.'/../../../config/statewars.php', ['dev_always_open' => false], $over);
}

it('refuses DEV_ALWAYS_OPEN in production', function () {
    expect(fn () => EnvValidator::validate(envConfig(['dev_always_open' => true]), 'production'))
        ->toThrow(RuntimeException::class, 'DEV_ALWAYS_OPEN must be false in production.');
    expect(fn () => EnvValidator::validate(envConfig(['dev_always_open' => true, 'node_env' => 'production']), 'local'))
        ->toThrow(RuntimeException::class, 'DEV_ALWAYS_OPEN must be false in production.');
});

it('allows DEV_ALWAYS_OPEN outside production', function () {
    expect(EnvValidator::validate(envConfig(['dev_always_open' => true]), 'local')['dev_always_open'])->toBeTrue();
});

it('rejects malformed values', function () {
    expect(fn () => EnvValidator::validate(envConfig(['battle_hour_wat' => '25']), 'local'))->toThrow(RuntimeException::class, 'BATTLE_HOUR_WAT');
    expect(fn () => EnvValidator::validate(envConfig(['dev_always_open' => 'yes']), 'local'))->toThrow(RuntimeException::class, 'DEV_ALWAYS_OPEN');
    expect(fn () => EnvValidator::validate(envConfig(['launch_date_wat' => '1/10/2026']), 'local'))->toThrow(RuntimeException::class, 'LAUNCH_DATE_WAT');
});
