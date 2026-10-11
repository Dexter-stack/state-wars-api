<?php

namespace App\Modules\Users\Repositories;

use App\Core\Db\Pg;
use App\Core\Errors;
use App\Core\JsMath;
use App\Modules\Users\Repositories\Contracts\UsersRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PgUsersRepository implements UsersRepository
{
    public function findById(string $id): ?UserRow
    {
        $r = DB::selectOne('SELECT * FROM users WHERE id = ?', [$id]);

        return $r ? self::row($r) : null;
    }

    public function usernameTaken(string $username): bool
    {
        return DB::selectOne('SELECT 1 AS one FROM users WHERE lower(username) = ?', [mb_strtolower($username)]) !== null;
    }

    public function create(string $username, string $stateCode, bool $remind): UserRow
    {
        try {
            // now() is the transaction time: created_at and state_changed_at are identical on purpose
            // (a player may correct their state once right after sign-up).
            $r = DB::selectOne(
                'INSERT INTO users (username, state_code, remind, state_changed_at, created_at) VALUES (?, ?, ?, now(), now()) RETURNING *',
                [$username, $stateCode, $remind],
            );
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw Errors::conflict('username_taken', "@{$username} is taken.");
            }
            throw $e;
        }

        return self::row($r);
    }

    public function updateState(string $id, string $stateCode, int $atMs): void
    {
        DB::update('UPDATE users SET state_code = ?, state_changed_at = ? WHERE id = ?', [$stateCode, Pg::ts($atMs), $id]);
    }

    public function updateRemind(string $id, bool $remind): void
    {
        DB::update('UPDATE users SET remind = ? WHERE id = ?', [$remind, $id]);
    }

    public function applyBattleResult(string $id, string $battleDate, int $points): array
    {
        // One atomic statement (port of users.repository.ts): no read-modify-write race between devices.
        $streak = 'CASE
            WHEN last_played_date = ?::date THEN streak
            WHEN last_played_date = ?::date - 1 THEN streak + 1
            ELSE 1 END';
        $r = DB::selectOne(
            "UPDATE users SET
                streak = {$streak},
                best_streak = GREATEST(best_streak, {$streak}),
                points = points + ?,
                last_played_date = GREATEST(COALESCE(last_played_date, ?::date), ?::date)
             WHERE id = ?
             RETURNING streak, best_streak, points",
            [$battleDate, $battleDate, $battleDate, $battleDate, $points, $battleDate, $battleDate, $id],
        );

        return ['streak' => (int) $r->streak, 'bestStreak' => (int) $r->best_streak, 'points' => (int) $r->points];
    }

    public function battleSummary(string $id): array
    {
        $r = DB::selectOne(
            'SELECT count(*)::int AS battles, coalesce(avg(score), 0)::float AS average FROM entries WHERE user_id = ? AND finished_at IS NOT NULL',
            [$id],
        );

        return ['battles' => (int) $r->battles, 'average' => JsMath::round1((float) $r->average)];
    }

    private static function row(object $r): UserRow
    {
        return new UserRow(
            id: $r->id,
            username: $r->username,
            stateCode: $r->state_code,
            stateChangedAt: Pg::ms($r->state_changed_at),
            role: $r->role,
            points: (int) $r->points,
            streak: (int) $r->streak,
            bestStreak: (int) $r->best_streak,
            lastPlayedDate: $r->last_played_date,
            remind: Pg::bool($r->remind),
            createdAt: Pg::ms($r->created_at),
        );
    }
}
