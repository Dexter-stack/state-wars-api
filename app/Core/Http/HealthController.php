<?php

namespace App\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Liveness: the process is up. Readiness: it can reach its dependencies (used by the load balancer). */
final class HealthController
{
    public function health(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::select('select 1');
            Redis::connection()->ping();

            return new JsonResponse(['ok' => true]);
        } catch (Throwable) {
            return new JsonResponse(['ok' => false], 503);
        }
    }
}
