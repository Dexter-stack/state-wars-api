<?php

namespace App\Modules\Users\Services;

use App\Core\Clock\Clock;
use App\Core\Config\GameConfig;
use App\Core\Errors;
use App\Core\WatTime;
use App\Modules\Catalog\Catalog;
use App\Modules\Users\Repositories\Contracts\UsersRepository;
use App\Modules\Users\Repositories\UserRow;
use App\Modules\Users\Support\Ranks;
use App\Shared\Contracts\PlayerSummary;

final class UsersService
{
    public const USERNAME_RE = '/^[A-Za-z0-9._]{3,16}$/';

    private const RESERVED = ['admin', 'support', 'statewars', 'moderator', 'official', 'help', 'root', 'system'];

    public function __construct(
        private readonly UsersRepository $users,
        private readonly Clock $clock,
        private readonly GameConfig $cfg,
    ) {}

    /** @return array{available: bool, reason?: string} */
    public function checkUsername(string $username): array
    {
        if (preg_match(self::USERNAME_RE, $username) !== 1) {
            return ['available' => false, 'reason' => 'Use 3 to 16 letters, numbers, dots or underscores.'];
        }
        if (in_array(strtolower($username), self::RESERVED, true)) {
            return ['available' => false, 'reason' => 'That name is reserved.'];
        }
        if ($this->users->usernameTaken($username)) {
            return ['available' => false, 'reason' => "@{$username} is taken."];
        }

        return ['available' => true];
    }

    public function register(string $username, string $stateCode, bool $remind): UserRow
    {
        $this->assertCanRegister($username, $stateCode);

        return $this->users->create($username, $stateCode, $remind);
    }

    /** The checks register() makes, on their own: lets sign-up refuse a bad name before it spends the emailed code. */
    public function assertCanRegister(string $username, string $stateCode): void
    {
        $check = $this->checkUsername($username);
        if (! $check['available']) {
            throw Errors::conflict('username_unavailable', $check['reason']);
        }
        if (! Catalog::isStateCode($stateCode)) {
            throw Errors::badRequest('Pick one of the 36 states or the FCT.');
        }
    }

    public function get(string $userId): UserRow
    {
        $u = $this->users->findById($userId);
        if (! $u) {
            throw Errors::unauthorized('Your account was not found. Sign in again.');
        }

        return $u;
    }

    public function summary(string $userId): PlayerSummary
    {
        $u = $this->get($userId);
        $s = $this->users->battleSummary($userId);
        $r = Ranks::rankFor($u->points);

        return new PlayerSummary(
            username: $u->username,
            stateCode: $u->stateCode,
            rank: $r['rank'],
            pointsToNext: $r['pointsToNext'],
            streak: $this->liveStreak($u),
            bestStreak: $u->bestStreak,
            battles: $s['battles'],
            average: $s['average'],
        );
    }

    /** A streak only counts if the player played today or yesterday (WAT). */
    private function liveStreak(UserRow $u): int
    {
        if ($u->lastPlayedDate === null) {
            return 0;
        }
        $now = $this->clock->nowMs();
        $today = WatTime::watDate($now);
        $yesterday = WatTime::watDate($now - WatTime::DAY_MS);

        return $u->lastPlayedDate === $today || $u->lastPlayedDate === $yesterday ? $u->streak : 0;
    }

    public function changeState(string $userId, string $stateCode): void
    {
        if (! Catalog::isStateCode($stateCode)) {
            throw Errors::badRequest('Pick one of the 36 states or the FCT.');
        }
        $u = $this->get($userId);
        if ($u->stateCode === $stateCode) {
            return;
        }
        $nextAllowed = $u->stateChangedAt + $this->cfg->stateChangeDays * WatTime::DAY_MS;
        // A first correction right after sign-up is free (created_at == state_changed_at until the first change).
        if ($this->clock->nowMs() < $nextAllowed && $u->createdAt !== $u->stateChangedAt) {
            throw Errors::conflict('state_change_cooldown', 'You can change your state again on '.WatTime::watDate($nextAllowed).'.');
        }
        $this->users->updateState($userId, $stateCode, $this->clock->nowMs());
    }

    public function setRemind(string $userId, bool $remind): void
    {
        $this->users->updateRemind($userId, $remind);
    }

    /** @return array{streak: int, bestStreak: int, points: int} */
    public function applyBattleResult(string $userId, string $battleDate, int $points): array
    {
        return $this->users->applyBattleResult($userId, $battleDate, $points);
    }
}
