<?php

namespace App\Modules\Auth\Repositories\Contracts;

use App\Modules\Auth\AuthUser;

/** Bearer sessions. Only a hash of each token is stored. */
interface SessionsRepository
{
    /** Issues a new token for the user and returns it in plain text (shown once, never stored). */
    public function create(string $userId): string;

    public function findUser(string $token): ?AuthUser;

    public function delete(string $token): void;
}
