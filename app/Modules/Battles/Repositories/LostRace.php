<?php

namespace App\Modules\Battles\Repositories;

use RuntimeException;

/** A duplicate request got there first: roll back and replay what it stored. */
final class LostRace extends RuntimeException {}
