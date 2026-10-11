<?php

namespace App\Modules\Auth\Services;

use App\Core\Cache\Cache;
use App\Core\Errors;
use App\Modules\Auth\AuthUser;
use App\Modules\Auth\Repositories\Contracts\SessionsRepository;
use App\Modules\Users\Services\UsersService;
use App\Shared\Contracts\PlayerSummary;

/**
 * Guest accounts with opaque bearer tokens (Sanctum). Only the SHA-256 of a token is stored.
 * Token lookups are cached (2s in-process, 10 min in Redis), so auth costs almost nothing at 8pm.
 * TODO: phone OTP sign-in for account recovery and leaderboard eligibility (SmsProvider: Termii / Africa's Talking).
 */
final class AuthService
{
    public function __construct(
        private readonly SessionsRepository $sessions,
        private readonly UsersService $users,
        private readonly Cache $cache,
    ) {}

    /** @return array{token: string, player: PlayerSummary} */
    public function registerGuest(string $username, string $stateCode, bool $remind): array
    {
        $user = $this->users->register($username, $stateCode, $remind);
        $token = $this->sessions->create($user->id);

        return ['token' => $token, 'player' => $this->users->summary($user->id)];
    }

    public function authenticate(string $token): AuthUser
    {
        $len = strlen($token);
        if ($len < 20 || $len > 100) {
            throw Errors::unauthorized();
        }
        $cached = $this->cache->getOrSet(self::key($token), 600, fn () => $this->sessions->findUser($token)?->toArray());
        if (! is_array($cached)) {
            throw Errors::unauthorized('Your session has ended. Sign in again.');
        }

        return new AuthUser($cached['userId'], $cached['role']);
    }

    public function logout(string $token): void
    {
        $this->sessions->delete($token);
        $this->cache->del(self::key($token));
    }

    private static function key(string $token): string
    {
        return 'sess:'.hash('sha256', $token);
    }
}
