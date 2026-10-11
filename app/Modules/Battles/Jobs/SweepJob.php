<?php

namespace App\Modules\Battles\Jobs;

use App\Modules\Battles\Services\BattlesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Every minute: close battles whose last player has had time to finish — settle abandoned entries in
 * batches of 500, write final state_results, mark finalized. Safe to rerun (finish() is exactly-once).
 */
final class SweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sweep'))->expireAfter(900)->dontRelease()];
    }

    public function handle(BattlesService $battles): void
    {
        foreach ($battles->dueForClose() as $b) {
            $r = $battles->closeBattle($b->id);
            Log::info('battle closed', ['battleId' => $b->id, 'kind' => $b->kind, 'subject' => $b->subject] + $r);
        }
    }
}
