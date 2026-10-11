<?php

use App\Modules\Battles\Support\Scoring;
use Illuminate\Support\Facades\DB;

/*
| Unit tests (tests/Unit) are plain PHP: services against in-memory fakes, no framework, no database.
| Feature tests (tests/Feature) boot the app against PostgreSQL (statewars_test) and Redis (db 15).
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/** Display index of the right answer for this entry's $index-th question (test helper; reads the answer key). */
function right(string $entryId, int $index): int
{
    $e = DB::table('entries')->where('id', $entryId)->first();
    $ids = json_decode(DB::table('battles')->where('id', $e->battle_id)->value(DB::raw('array_to_json(question_ids)')), true);
    $qid = $ids[Scoring::questionOrder((int) $e->seed, count($ids))[$index]];
    $answer = (int) DB::table('questions')->where('id', $qid)->value('answer');

    return Scoring::toDisplay(Scoring::optionOrder((int) $e->seed, $index), $answer);
}

