<?php

namespace App\Modules\Auth\Services;

use App\Core\AppException;
use App\Core\Errors;
use App\Modules\Auth\Repositories\Contracts\CredentialsRepository;
use App\Modules\Auth\Stores\Contracts\CodeStore;
use App\Modules\Auth\Support\Contracts\CodeMailer;
use App\Modules\Auth\Support\Contracts\PasswordHasher;
use App\Modules\Users\Services\UsersService;
use App\Shared\Contracts\PlayerSummary;

/**
 * Signing in to an existing account and getting it back.
 *
 * Every new account is created with an email (proved with a 6-digit code) and a password, so the player can
 * sign in anywhere with their username or email and reset a forgotten password through that email.
 * Guest accounts from before this rule, which have neither, can attach them later (requestEmail + confirm).
 *
 * Rules it guarantees:
 *  - An email is only saved after its owner entered the code sent to it.
 *  - Codes are single-use, last CODE_TTL_SEC and die after CODE_ATTEMPTS wrong tries. Only a hash is stored.
 *  - Nothing here says whether an email or username has an account, except to the signed-in player securing theirs.
 *  - A password reset ends every session of the account; a password change ends every other one.
 */
final class AccountService
{
    public const CODE_TTL_SEC = 600;

    private const CODE_ATTEMPTS = 5;

    private const RESEND_SEC = 60;

    private const MAX_FAILS = 10;

    private const FAIL_WINDOW_SEC = 900;

    public function __construct(
        private readonly CredentialsRepository $credentials,
        private readonly AuthService $auth,
        private readonly UsersService $users,
        private readonly CodeStore $codes,
        private readonly CodeMailer $mailer,
        private readonly PasswordHasher $hasher,
    ) {}

    /**
     * Sign-up, step 1: emails a code to the address a new player wants to use.
     * Sent even when the address already has an account, so asking never reveals who has one.
     */
    public function requestSignupCode(string $email): void
    {
        $key = self::emailKey($email);
        if ($this->codes->hit("send:signup:{$key}", self::RESEND_SEC) > 1) {
            throw Errors::tooMany('Wait a minute before asking for another code.');
        }
        $code = self::newCode();
        $this->codes->put("signup:{$key}", self::codeHash($code, $email), self::CODE_TTL_SEC);
        $this->mailer->send($email, '', $code, CodeMailer::SIGNUP, intdiv(self::CODE_TTL_SEC, 60));
    }

    /**
     * Sign-up, step 2: creates the account with its email and password already attached.
     * The username and state are checked first, so a taken name doesn't use up the code.
     *
     * @return array{token: string, player: PlayerSummary}
     */
    public function signUp(string $username, string $stateCode, bool $remind, string $email, string $code, string $password): array
    {
        $this->users->assertCanRegister($username, $stateCode);
        if ($this->codes->check('signup:'.self::emailKey($email), self::codeHash($code, $email), self::CODE_ATTEMPTS) !== CodeStore::OK) {
            throw Errors::invalidCode();
        }
        // only the owner of the address gets this far, so it is safe to say it is taken
        if ($this->credentials->findByEmail($email) !== null) {
            throw Errors::conflict('email_taken', 'That email already has an account. Sign in instead.');
        }
        $user = $this->users->register($username, $stateCode, $remind);
        // cannot lose a race: the code for this address is single-use, so no second sign-up reaches this line with it
        if (! $this->credentials->attach($user->id, $email, $this->hasher->hash($password))) {
            throw self::emailTaken();
        }

        return $this->session($user->id);
    }

    /** @return array{secured: bool, email?: string} */
    public function status(string $userId): array
    {
        $email = $this->credentialsOf($userId)['email'];

        return $email === null ? ['secured' => false] : ['secured' => true, 'email' => $email];
    }

    /** Step 1 of securing an account: emails a code to the address the player wants to attach. */
    public function requestEmail(string $userId, string $email): void
    {
        if ($this->credentialsOf($userId)['email'] !== null) {
            throw self::alreadySecured();
        }
        if ($this->credentials->findByEmail($email) !== null) {
            throw self::emailTaken();
        }
        if ($this->codes->hit("send:secure:{$userId}", self::RESEND_SEC) > 1) {
            throw Errors::tooMany('Wait a minute before asking for another code.');
        }
        $code = self::newCode();
        $this->codes->put("secure:{$userId}", self::codeHash($code, $email), self::CODE_TTL_SEC);
        $this->mailer->send($email, $this->users->get($userId)->username, $code, CodeMailer::SECURE, intdiv(self::CODE_TTL_SEC, 60));
    }

    /**
     * Step 2: the code proves the email is theirs; the email and password are saved together.
     *
     * @return array{secured: bool, email?: string}
     */
    public function confirm(string $userId, string $email, string $code, string $password): array
    {
        if ($this->credentialsOf($userId)['email'] !== null) {
            throw self::alreadySecured();
        }
        // the hash covers the email, so a code sent to one address can't confirm another
        if ($this->codes->check("secure:{$userId}", self::codeHash($code, $email), self::CODE_ATTEMPTS) !== CodeStore::OK) {
            throw Errors::invalidCode();
        }
        if (! $this->credentials->attach($userId, $email, $this->hasher->hash($password))) {
            throw self::emailTaken();
        }

        return $this->status($userId);
    }

    /** @return array{token: string, player: PlayerSummary} */
    public function login(string $identifier, string $password): array
    {
        $failKey = 'login:'.hash('sha256', mb_strtolower($identifier));
        if ($this->codes->hit($failKey, self::FAIL_WINDOW_SEC) > self::MAX_FAILS) {
            throw Errors::tooMany('Too many tries. Wait 15 minutes, or reset your password.');
        }
        $found = $this->credentials->findByLogin($identifier);
        if ($found === null) {
            $this->hasher->hash($password); // same work as a real check, so timing doesn't reveal which names exist
            throw Errors::invalidCredentials();
        }
        if (! $this->hasher->verify($password, $found['passwordHash'])) {
            throw Errors::invalidCredentials();
        }
        $this->codes->clear($failKey);

        return $this->session($found['userId']);
    }

    /** Emails a reset code if the address has an account. Says nothing either way. */
    public function forgot(string $email): void
    {
        $account = $this->credentials->findByEmail($email);
        $key = self::emailKey($email);
        if ($account === null || $this->codes->hit("send:reset:{$key}", self::RESEND_SEC) > 1) {
            return;
        }
        $code = self::newCode();
        $this->codes->put("reset:{$key}", self::codeHash($code, $email), self::CODE_TTL_SEC);
        $this->mailer->send($email, $account['username'], $code, CodeMailer::RESET, intdiv(self::CODE_TTL_SEC, 60));
    }

    /**
     * Sets a new password with the emailed code, signs every device out and returns a fresh session.
     *
     * @return array{token: string, player: PlayerSummary}
     */
    public function reset(string $email, string $code, string $password): array
    {
        if ($this->codes->check('reset:'.self::emailKey($email), self::codeHash($code, $email), self::CODE_ATTEMPTS) !== CodeStore::OK) {
            throw Errors::invalidCode();
        }
        $account = $this->credentials->findByEmail($email);
        if ($account === null) {
            throw Errors::invalidCode();
        }
        $this->credentials->updatePassword($account['userId'], $this->hasher->hash($password));
        $this->auth->revokeAll($account['userId']);
        $this->codes->clear('login:'.hash('sha256', mb_strtolower($email)));
        $this->codes->clear('login:'.hash('sha256', mb_strtolower($account['username'])));

        return $this->session($account['userId']);
    }

    /** Changes the password of a signed-in player and signs their other devices out. */
    public function changePassword(string $userId, string $currentToken, string $current, string $new): void
    {
        $hash = $this->credentialsOf($userId)['passwordHash'];
        if ($hash === null) {
            throw Errors::conflict('not_secured', 'Add an email and password to your account first.');
        }
        if ($this->codes->hit("pw:{$userId}", self::FAIL_WINDOW_SEC) > self::MAX_FAILS) {
            throw Errors::tooMany('Too many tries. Wait 15 minutes, or reset your password.');
        }
        if (! $this->hasher->verify($current, $hash)) {
            throw Errors::conflict('wrong_password', 'Your current password is wrong.');
        }
        $this->credentials->updatePassword($userId, $this->hasher->hash($new));
        $this->codes->clear("pw:{$userId}");
        $this->auth->revokeAll($userId, $currentToken);
    }

    /** @return array{email: ?string, passwordHash: ?string} */
    private function credentialsOf(string $userId): array
    {
        return $this->credentials->forUser($userId) ?? throw Errors::unauthorized('Your account was not found. Sign in again.');
    }

    /** @return array{token: string, player: PlayerSummary} */
    private function session(string $userId): array
    {
        return ['token' => $this->auth->issue($userId), 'player' => $this->users->summary($userId)];
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    private static function codeHash(string $code, string $email): string
    {
        return hash('sha256', $code.'|'.mb_strtolower($email));
    }

    private static function emailKey(string $email): string
    {
        return hash('sha256', mb_strtolower($email));
    }

    private static function alreadySecured(): AppException
    {
        return Errors::conflict('already_secured', 'This account already has an email and password.');
    }

    private static function emailTaken(): AppException
    {
        return Errors::conflict('email_taken', 'That email is already used by another account.');
    }
}
