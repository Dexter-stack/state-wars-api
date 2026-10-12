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

    /**
     * A stable id for a token that can also be derived from its stored row, never the token itself.
     * Used as the cache key, so sessions can be dropped from the cache without knowing their tokens.
     */
    public function fingerprint(string $token): string;

    /**
     * Ends every session of a user, except $exceptToken when given.
     *
     * @return list<string> fingerprints of the sessions that were ended
     */
    public function deleteAllForUser(string $userId, ?string $exceptToken = null): array;
}
