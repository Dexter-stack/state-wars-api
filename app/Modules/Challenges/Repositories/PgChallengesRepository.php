<?php

namespace App\Modules\Challenges\Repositories;

use App\Core\Db\Pg;
use App\Modules\Challenges\Repositories\Contracts\ChallengesRepository;
use Illuminate\Support\Facades\DB;

final class PgChallengesRepository implements ChallengesRepository
{
    public function create(string $fromUser, string $battleId): string
    {
        return DB::selectOne('INSERT INTO challenges (from_user, battle_id) VALUES (?, ?) RETURNING id', [$fromUser, $battleId])->id;
    }

    public function find(string $id): ?array
    {
        $r = DB::selectOne('SELECT id, from_user, battle_id, to_user FROM challenges WHERE id = ?', [$id]);

        return $r ? ['id' => $r->id, 'fromUser' => $r->from_user, 'battleId' => $r->battle_id, 'toUser' => $r->to_user] : null;
    }

    public function accept(string $id, string $toUser, int $atMs): bool
    {
        return DB::update(
            'UPDATE challenges SET to_user = ?, accepted_at = ? WHERE id = ? AND to_user IS NULL AND from_user <> ?',
            [$toUser, Pg::ts($atMs), $id, $toUser],
        ) > 0;
    }

    public function listForUser(string $userId, int $limit): array
    {
        $rows = DB::select(
            'SELECT c.id, c.battle_id, c.from_user, fu.username AS from_name, fu.state_code AS from_state, fe.score AS from_score,
                    c.to_user, tu.username AS to_name, tu.state_code AS to_state, te.score AS to_score
             FROM challenges c
             JOIN users fu ON fu.id = c.from_user
             LEFT JOIN users tu ON tu.id = c.to_user
             LEFT JOIN entries fe ON fe.battle_id = c.battle_id AND fe.user_id = c.from_user AND fe.finished_at IS NOT NULL
             LEFT JOIN entries te ON te.battle_id = c.battle_id AND te.user_id = c.to_user AND te.finished_at IS NOT NULL
             WHERE c.from_user = ? OR c.to_user = ?
             ORDER BY c.created_at DESC LIMIT ?',
            [$userId, $userId, $limit],
        );

        return array_map(fn ($r) => [
            'id' => $r->id, 'battleId' => $r->battle_id,
            'fromUser' => $r->from_user, 'fromName' => $r->from_name, 'fromState' => $r->from_state,
            'fromScore' => $r->from_score === null ? null : (int) $r->from_score,
            'toUser' => $r->to_user, 'toName' => $r->to_name, 'toState' => $r->to_state,
            'toScore' => $r->to_score === null ? null : (int) $r->to_score,
        ], $rows);
    }
}
