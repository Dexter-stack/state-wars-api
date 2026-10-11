<?php

namespace App\Modules\Challenges\Repositories\Contracts;

interface ChallengesRepository
{
    public function create(string $fromUser, string $battleId): string;

    /** @return array{id: string, fromUser: string, battleId: string, toUser: ?string}|null */
    public function find(string $id): ?array;

    /** Claims the challenge for $toUser if nobody has. Returns false if already taken. */
    public function accept(string $id, string $toUser, int $atMs): bool;

    /**
     * Newest first. Scores are null until that player has FINISHED the battle.
     *
     * @return list<array{id: string, battleId: string, fromUser: string, fromName: string, fromState: string, fromScore: ?int,
     *                    toUser: ?string, toName: ?string, toState: ?string, toScore: ?int}>
     */
    public function listForUser(string $userId, int $limit): array;
}
