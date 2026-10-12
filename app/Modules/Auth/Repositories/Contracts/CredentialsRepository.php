<?php

namespace App\Modules\Auth\Repositories\Contracts;

/** The email and password a player may attach to their account. Emails are stored and compared in lower case. */
interface CredentialsRepository
{
    /** @return array{email: ?string, passwordHash: ?string}|null null when the user doesn't exist */
    public function forUser(string $userId): ?array;

    /**
     * The account a sign-in names, by username or email (case-insensitive). Only accounts with a password.
     *
     * @return array{userId: string, passwordHash: string}|null
     */
    public function findByLogin(string $identifier): ?array;

    /** @return array{userId: string, username: string}|null */
    public function findByEmail(string $email): ?array;

    /** Attaches credentials to an account that has none. False when it already has them or the email is in use. */
    public function attach(string $userId, string $email, string $passwordHash): bool;

    public function updatePassword(string $userId, string $passwordHash): void;
}
