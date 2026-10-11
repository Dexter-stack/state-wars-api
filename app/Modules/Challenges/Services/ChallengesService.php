<?php

namespace App\Modules\Challenges\Services;

use App\Core\Clock\Clock;
use App\Core\Config\GameConfig;
use App\Core\Errors;
use App\Modules\Battles\Repositories\Contracts\EntriesRepository;
use App\Modules\Catalog\Catalog;
use App\Modules\Challenges\Repositories\Contracts\ChallengesRepository;

/**
 * Head-to-head bragging rights. Challenges never change the state table: this service has no access to
 * the leaderboard, live scores or state results, only to challenges and (read-only) entries.
 */
final class ChallengesService
{
    public function __construct(
        private readonly ChallengesRepository $challenges,
        private readonly EntriesRepository $entries,
        private readonly Clock $clock,
        private readonly GameConfig $cfg,
    ) {}

    /** @return array{id: string, url: string} */
    public function create(string $userId, string $entryId): array
    {
        $e = $this->entries->findById($entryId);
        if (! $e || $e->userId !== $userId) {
            throw Errors::notFound('Battle entry');
        }
        if ($e->finishedAt === null) {
            throw Errors::conflict('not_finished', 'Finish your battle before challenging someone.');
        }
        $id = $this->challenges->create($userId, $e->battleId);

        return ['id' => $id, 'url' => "{$this->cfg->publicWebUrl}/?challenge={$id}"];
    }

    public function accept(string $userId, string $id): void
    {
        $c = $this->challenges->find($id);
        if (! $c) {
            throw Errors::notFound('Challenge');
        }
        if ($c['fromUser'] === $userId) {
            throw Errors::badRequest("You can't accept your own challenge.");
        }
        if ($c['toUser'] === $userId) {
            return;
        }
        if (! $this->challenges->accept($id, $userId, $this->clock->nowMs())) {
            throw Errors::conflict('challenge_taken', 'Someone else already took this challenge.');
        }
    }

    /** @return list<array{id: string, username: string, state: string, status: string, score?: string}> contracts.ts Challenge[] */
    public function list(string $userId): array
    {
        return array_map(function (array $c) use ($userId) {
            $mine = $c['fromUser'] === $userId;
            $name = $mine ? $c['toName'] : $c['fromName'];
            $state = $mine ? $c['toState'] : $c['fromState'];
            $myScore = $mine ? $c['fromScore'] : $c['toScore'];
            $theirs = $mine ? $c['toScore'] : $c['fromScore'];
            $base = ['id' => $c['id'], 'username' => $name ?? 'Waiting for a friend', 'state' => $state ? Catalog::stateName($state) : ''];
            if ($myScore === null || $theirs === null) {
                return $base + ['status' => 'waiting'];
            }
            $status = $myScore > $theirs ? 'won' : ($myScore < $theirs ? 'lost' : 'draw');

            return $base + ['status' => $status, 'score' => max($myScore, $theirs).'–'.min($myScore, $theirs)];
        }, $this->challenges->listForUser($userId, 20));
    }
}
