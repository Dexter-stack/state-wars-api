<?php

namespace App\Modules\Auth\Repositories;

use App\Modules\Auth\Repositories\Contracts\CredentialsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PgCredentialsRepository implements CredentialsRepository
{
    public function forUser(string $userId): ?array
    {
        $r = DB::selectOne('SELECT email, password_hash FROM users WHERE id = ?', [$userId]);

        return $r ? ['email' => $r->email, 'passwordHash' => $r->password_hash] : null;
    }

    public function findByLogin(string $identifier): ?array
    {
        $id = mb_strtolower($identifier);
        $r = DB::selectOne(
            'SELECT id, password_hash FROM users
             WHERE password_hash IS NOT NULL AND (lower(username) = ? OR lower(email) = ?)
             ORDER BY (lower(email) = ?) DESC LIMIT 1',
            [$id, $id, $id],
        );

        return $r ? ['userId' => $r->id, 'passwordHash' => $r->password_hash] : null;
    }

    public function findByEmail(string $email): ?array
    {
        $r = DB::selectOne('SELECT id, username FROM users WHERE lower(email) = ?', [mb_strtolower($email)]);

        return $r ? ['userId' => $r->id, 'username' => $r->username] : null;
    }

    public function attach(string $userId, string $email, string $passwordHash): bool
    {
        try {
            // conditional on having none: two confirms in a row never overwrite each other
            return DB::update(
                'UPDATE users SET email = ?, password_hash = ? WHERE id = ? AND email IS NULL',
                [mb_strtolower($email), $passwordHash, $userId],
            ) === 1;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return false; // another account confirmed this email first
            }
            throw $e;
        }
    }

    public function updatePassword(string $userId, string $passwordHash): void
    {
        DB::update('UPDATE users SET password_hash = ? WHERE id = ? AND email IS NOT NULL', [$passwordHash, $userId]);
    }
}
