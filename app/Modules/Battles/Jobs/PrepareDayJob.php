<?php

namespace App\Modules\Battles\Jobs;

use App\Core\Clock\Clock;
use App\Core\WatTime;
use App\Modules\Battles\Services\SchedulerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/** 00:05 Africa/Lagos: create today's battles (daily + leagues), Uprising states on Fridays. Idempotent. */
final class PrepareDayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly ?string $date = null) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('prepare-day'))->expireAfter(300)->dontRelease()];
    }

    public function handle(SchedulerService $scheduler, Clock $clock): void
    {
        $date = $this->date ?? WatTime::watDate($clock->nowMs());
        $r = $scheduler->prepareDay($date);
        Log::info('prepare-day', ['date' => $date] + $r);
    }
}
