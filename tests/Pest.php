<?php

/*
| Unit tests (tests/Unit) are plain PHP: services against in-memory fakes, no framework, no database.
| Feature tests (tests/Feature) boot the app against PostgreSQL (statewars_test) and Redis (db 15).
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
