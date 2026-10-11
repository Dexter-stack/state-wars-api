<?php

namespace App\Modules\Auth\Repositories;

use App\Modules\Auth\AuthUser;
use App\Modules\Auth\Repositories\Contracts\SessionsRepository;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sanctum personal access tokens ("{id}|{secret}", SHA-256 of the secret stored).
 * Lookups are one indexed query joined to users, without Sanctum's per-request last_used_at write.
 */
final class SanctumSessionsRepository implements SessionsRepository
{
    public function create(string $userId): string
    {
        return (new User(['id' => $userId]))->forceFill(['id' => $userId])->createToken('guest')->plainTextToken;
    }

    public function findUser(string $token): ?AuthUser
    {
        $parsed = self::parse($token);
        if ($parsed === null) {
            return null;
        }
        [$id, $secret] = $parsed;
        $row = DB::selectOne(
            'SELECT t.token, u.id, u.role FROM personal_access_tokens t JOIN users u ON u.id = t.tokenable_id
             WHERE t.id = ? AND (t.expires_at IS NULL OR t.expires_at > now())',
            [$id],
        );
        if (! $row || ! hash_equals($row->token, hash('sha256', $secret))) {
            return null;
        }

        return new AuthUser($row->id, $row->role);
    }

    public function delete(string $token): void
    {
        $parsed = self::parse($token);
        if ($parsed !== null) {
            DB::delete('DELETE FROM personal_access_tokens WHERE id = ? AND token = ?', [$parsed[0], hash('sha256', $parsed[1])]);
        }
    }

    /** @return array{0: int, 1: string}|null */
    private static function parse(string $token): ?array
    {
        if (! str_contains($token, '|')) {
            return null;
        }
        [$id, $secret] = explode('|', $token, 2);
        if (! ctype_digit($id) || $secret === '' || strlen($id) > 18) {
            return null;
        }

        return [(int) $id, $secret];
    }
}
