<?php

namespace App\Modules\Battles\Repositories;

use App\Core\Db\Pg;
use App\Modules\Battles\Repositories\Contracts\BattlesRepository;
use App\Modules\Battles\Support\BattleView;
use Illuminate\Support\Facades\DB;

final class PgBattlesRepository implements BattlesRepository
{
    private const SELECT = "SELECT id, battle_date::text AS battle_date, kind, subject, day_number, opens_at, closes_at,
        array_to_json(question_ids) AS question_ids, array_to_json(uprising_states) AS uprising_states, status FROM battles";

    private const RETURNING = "RETURNING id, battle_date::text AS battle_date, kind, subject, day_number, opens_at, closes_at,
        array_to_json(question_ids) AS question_ids, array_to_json(uprising_states) AS uprising_states, status";

    public function findById(string $id): ?BattleView
    {
        return self::view(DB::selectOne(self::SELECT.' WHERE id = ?', [$id]));
    }

    public function findSlot(string $date, string $kind, ?string $subject): ?BattleView
    {
        return self::view(DB::selectOne(
            self::SELECT." WHERE battle_date = ? AND kind = ? AND coalesce(subject, '') = ?",
            [$date, $kind, $subject ?? ''],
        ));
    }

    public function findOpen(string $kind, ?string $subject, int $nowMs, bool $anyTime): ?BattleView
    {
        $now = Pg::ts($nowMs);
        $time = $anyTime
            ? "opens_at <= ?::timestamptz + interval '1 day'"
            : 'opens_at <= ?::timestamptz AND closes_at > ?::timestamptz';
        $bindings = $anyTime ? [$kind, $subject ?? '', $now] : [$kind, $subject ?? '', $now, $now];

        return self::view(DB::selectOne(
            self::SELECT." WHERE kind = ? AND coalesce(subject, '') = ? AND {$time} ORDER BY opens_at DESC LIMIT 1",
            $bindings,
        ));
    }

    public function findNextAfter(string $kind, ?string $subject, int $nowMs): ?BattleView
    {
        return self::view(DB::selectOne(
            self::SELECT." WHERE kind = ? AND coalesce(subject, '') = ? AND opens_at > ? ORDER BY opens_at ASC LIMIT 1",
            [$kind, $subject ?? '', Pg::ts($nowMs)],
        ));
    }

    public function create(string $date, string $kind, ?string $subject, int $dayNumber, int $opensAt, int $closesAt, array $questionIds, array $uprisingStates): BattleView
    {
        $r = DB::selectOne(
            'INSERT INTO battles (battle_date, kind, subject, day_number, opens_at, closes_at, question_ids, uprising_states)
             VALUES (?, ?, ?, ?, ?, ?, '.Pg::arrayParam('uuid').', '.Pg::arrayParam('text').') ON CONFLICT DO NOTHING '.self::RETURNING,
            [$date, $kind, $subject, $dayNumber, Pg::ts($opensAt), Pg::ts($closesAt), Pg::json($questionIds), Pg::json($uprisingStates)],
        );

        return self::view($r) ?? $this->findSlot($date, $kind, $subject);
    }

    public function listScheduledClosedBefore(int $cutoffMs): array
    {
        return array_map(fn ($r) => self::view($r), DB::select(
            self::SELECT." WHERE status = 'scheduled' AND closes_at <= ?",
            [Pg::ts($cutoffMs)],
        ));
    }

    public function markFinalized(string $id): void
    {
        DB::update("UPDATE battles SET status = 'finalized' WHERE id = ?", [$id]);
    }

    private static function view(?object $r): ?BattleView
    {
        if (! $r) {
            return null;
        }

        return new BattleView(
            id: $r->id,
            date: $r->battle_date,
            kind: $r->kind,
            subject: $r->subject,
            dayNumber: (int) $r->day_number,
            opensAt: Pg::ms($r->opens_at),
            closesAt: Pg::ms($r->closes_at),
            questionIds: Pg::decodeArray($r->question_ids),
            uprisingStates: Pg::decodeArray($r->uprising_states),
            status: $r->status,
        );
    }
}
