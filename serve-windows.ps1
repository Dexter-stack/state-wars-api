# Starts the API on RoadRunner with Octane's worker, on Windows.
# `php artisan octane:start` cannot run here: it needs POSIX signal constants (pcntl) that Windows PHP lacks.
# These are the same options octane:start passes to RoadRunner. Stop with Ctrl+C.
param([int]$Port = 8080, [int]$Workers = 8, [int]$MaxRequests = 1000)

$env:PHP_INI_SCAN_DIR = "$PSScriptRoot\php-ini" # loads ext-sockets for this project only
$env:APP_BASE_PATH = $PSScriptRoot
$env:LARAVEL_OCTANE = '1'
if (-not $env:APP_ENV) { $env:APP_ENV = 'local' }

Set-Location $PSScriptRoot
& "$PSScriptRoot\rr.exe" -c .rr.yaml `
    -o version=3 `
    -o "http.address=:$Port" `
    -o 'server.command=php,vendor/bin/roadrunner-worker' `
    -o "http.pool.num_workers=$Workers" `
    -o "http.pool.max_jobs=$MaxRequests" `
    -o 'rpc.listen=tcp://127.0.0.1:6001' `
    -o 'http.pool.supervisor.exec_ttl=10s' `
    -o 'logs.output=stdout' `
    serve
