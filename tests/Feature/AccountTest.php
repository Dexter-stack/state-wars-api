<?php

// Sign-in to an existing account and recovery: securing a guest account with an email and password,
// signing in on another device, forgotten and changed passwords.

use App\Modules\Auth\Jobs\SendCodeEmailJob;
use App\Modules\Auth\Support\Contracts\CodeMailer;
use App\Modules\Auth\Support\LaravelCodeMailer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\RecordingCodeMailer;

const PASSWORD = 'correct horse 9';

beforeEach(function () {
    DatabaseSeeder::seed(20, 15, '2026-10-01', Carbon::now()->getTimestampMs());
    config(['statewars.rate_signin_per_min' => 100000]);
    $this->mail = new RecordingCodeMailer;
    $this->app->instance(CodeMailer::class, $this->mail);
});

function guestToken(string $username = 'tobi_codes'): string
{
    return test()->postJson('/v1/auth/guest', ['username' => $username, 'stateCode' => 'KW'])->assertStatus(201)->json('token');
}

function asPlayer(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

/** Signs a guest up and secures the account; returns their token. */
function securedPlayer(string $username = 'tobi_codes', string $email = 'tobi@example.com'): string
{
    $t = guestToken($username);
    test()->postJson('/v1/auth/account/email', ['email' => $email], asPlayer($t))->assertStatus(204);
    test()->postJson('/v1/auth/account/confirm', ['email' => $email, 'code' => test()->mail->lastCode(), 'password' => PASSWORD], asPlayer($t))->assertOk();

    return $t;
}

function wrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

it('reports a guest account as not secured and needs a token for every account route', function () {
    $t = guestToken();
    $this->getJson('/v1/auth/account', asPlayer($t))->assertOk()->assertExactJson(['secured' => false]);

    $this->getJson('/v1/auth/account')->assertStatus(401);
    $this->postJson('/v1/auth/account/email', ['email' => 'a@example.com'])->assertStatus(401);
    $this->postJson('/v1/auth/account/confirm', ['email' => 'a@example.com', 'code' => '123456', 'password' => PASSWORD])->assertStatus(401);
    $this->postJson('/v1/auth/password/change', ['currentPassword' => PASSWORD, 'newPassword' => PASSWORD])->assertStatus(401);
});

it('secures an account once the emailed code is entered', function () {
    $t = guestToken();
    $this->postJson('/v1/auth/account/email', ['email' => '  Tobi@Example.com '], asPlayer($t))->assertStatus(204)->assertNoContent();

    expect($this->mail->sent)->toHaveCount(1);
    $mail = $this->mail->sent[0];
    expect($mail['email'])->toBe('tobi@example.com')->and($mail['username'])->toBe('tobi_codes')
        ->and($mail['purpose'])->toBe('secure')->and($mail['validMinutes'])->toBe(10)->and($mail['code'])->toMatch('/^\d{6}$/');
    expect(DB::table('users')->where('username', 'tobi_codes')->value('email'))->toBeNull(); // nothing saved before the code

    $this->postJson('/v1/auth/account/confirm', ['email' => 'tobi@example.com', 'code' => wrongCode($mail['code']), 'password' => PASSWORD], asPlayer($t))
        ->assertStatus(400)->assertExactJson(['error' => ['code' => 'invalid_code', 'message' => 'That code is wrong or has expired. Ask for a new one.']]);

    $this->postJson('/v1/auth/account/confirm', ['email' => 'TOBI@example.com', 'code' => " {$mail['code']} ", 'password' => PASSWORD], asPlayer($t))
        ->assertOk()->assertExactJson(['secured' => true, 'email' => 'tobi@example.com']);
    $this->getJson('/v1/auth/account', asPlayer($t))->assertExactJson(['secured' => true, 'email' => 'tobi@example.com']);

    $row = DB::table('users')->where('username', 'tobi_codes')->first();
    expect($row->email)->toBe('tobi@example.com')->and($row->password_hash)->not->toBe(PASSWORD)->and(password_verify(PASSWORD, $row->password_hash))->toBeTrue();

    // the code is spent and the account can't be re-secured through this route
    $this->postJson('/v1/auth/account/email', ['email' => 'other@example.com'], asPlayer($t))->assertStatus(409)->assertJsonPath('error.code', 'already_secured');
    $this->postJson('/v1/auth/account/confirm', ['email' => 'other@example.com', 'code' => $mail['code'], 'password' => PASSWORD], asPlayer($t))
        ->assertStatus(409)->assertJsonPath('error.code', 'already_secured');
});

it('ties a code to the email it was sent to and to the player who asked', function () {
    $t = guestToken();
    $other = guestToken('ada_1');
    $this->postJson('/v1/auth/account/email', ['email' => 'tobi@example.com'], asPlayer($t))->assertStatus(204);
    $code = $this->mail->lastCode();

    $this->postJson('/v1/auth/account/confirm', ['email' => 'evil@example.com', 'code' => $code, 'password' => PASSWORD], asPlayer($t))
        ->assertStatus(400)->assertJsonPath('error.code', 'invalid_code');
    $this->postJson('/v1/auth/account/confirm', ['email' => 'tobi@example.com', 'code' => $code, 'password' => PASSWORD], asPlayer($other))
        ->assertStatus(400)->assertJsonPath('error.code', 'invalid_code');
    $this->postJson('/v1/auth/account/confirm', ['email' => 'tobi@example.com', 'code' => $code, 'password' => PASSWORD], asPlayer($t))->assertOk();
});

it('destroys a code after 5 wrong tries', function () {
    $t = guestToken();
    $this->postJson('/v1/auth/account/email', ['email' => 'tobi@example.com'], asPlayer($t))->assertStatus(204);
    $code = $this->mail->lastCode();
    $body = ['email' => 'tobi@example.com', 'code' => wrongCode($code), 'password' => PASSWORD];
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/v1/auth/account/confirm', $body, asPlayer($t))->assertStatus(400);
    }
    $this->postJson('/v1/auth/account/confirm', ['code' => $code] + $body, asPlayer($t))->assertStatus(400)->assertJsonPath('error.code', 'invalid_code');
    $this->getJson('/v1/auth/account', asPlayer($t))->assertExactJson(['secured' => false]);
});

it('sends one code a minute and refuses an email another account owns', function () {
    securedPlayer();
    $t = guestToken('ada_1');
    $this->postJson('/v1/auth/account/email', ['email' => 'Tobi@example.com'], asPlayer($t))->assertStatus(409)
        ->assertExactJson(['error' => ['code' => 'email_taken', 'message' => 'That email is already used by another account.']]);

    $sent = count($this->mail->sent);
    $this->postJson('/v1/auth/account/email', ['email' => 'ada@example.com'], asPlayer($t))->assertStatus(204);
    $this->postJson('/v1/auth/account/email', ['email' => 'ada@example.com'], asPlayer($t))->assertStatus(429)
        ->assertExactJson(['error' => ['code' => 'rate_limited', 'message' => 'Wait a minute before asking for another code.']]);
    expect($this->mail->sent)->toHaveCount($sent + 1);
});

it('signs in with username or email and the same error for every failure', function () {
    $first = securedPlayer();
    guestToken('guest_only');

    $r = $this->postJson('/v1/auth/login', ['identifier' => ' TOBI_codes ', 'password' => PASSWORD])->assertOk();
    expect(array_keys($r->json()))->toBe(['token', 'player'])->and($r->json('player.username'))->toBe('tobi_codes')
        ->and(array_keys($r->json('player')))->toBe(['username', 'stateCode', 'rank', 'pointsToNext', 'streak', 'bestStreak', 'battles', 'average']);
    $second = $r->json('token');
    expect($second)->not->toBe($first);
    $this->getJson('/v1/me', asPlayer($second))->assertOk()->assertJsonPath('username', 'tobi_codes');
    $this->getJson('/v1/me', asPlayer($first))->assertOk(); // the first device stays signed in

    $this->postJson('/v1/auth/login', ['identifier' => 'Tobi@Example.com', 'password' => PASSWORD])->assertOk()->assertJsonPath('player.username', 'tobi_codes');

    $denied = ['error' => ['code' => 'invalid_credentials', 'message' => 'Wrong username, email or password.']];
    $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => 'wrong password'])->assertStatus(401)->assertExactJson($denied);
    $this->postJson('/v1/auth/login', ['identifier' => 'nobody_here', 'password' => PASSWORD])->assertStatus(401)->assertExactJson($denied);
    $this->postJson('/v1/auth/login', ['identifier' => 'guest_only', 'password' => PASSWORD])->assertStatus(401)->assertExactJson($denied);
    $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => PASSWORD.' '])->assertStatus(401); // passwords are not trimmed
});

it('locks sign-in for a name after 10 tries, even with the right password', function () {
    securedPlayer();
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => 'wrong password'])->assertStatus(401);
    }
    $this->postJson('/v1/auth/login', ['identifier' => 'Tobi_Codes', 'password' => PASSWORD])->assertStatus(429)
        ->assertExactJson(['error' => ['code' => 'rate_limited', 'message' => 'Too many tries. Wait 15 minutes, or reset your password.']]);
    $this->postJson('/v1/auth/login', ['identifier' => 'tobi@example.com', 'password' => PASSWORD])->assertOk(); // counted per name typed
});

it('resets a forgotten password, signs every device out and returns a new session', function () {
    $old = securedPlayer();
    $other = $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => PASSWORD])->json('token');
    $this->getJson('/v1/me', asPlayer($old))->assertOk(); // both sessions now cached
    $this->getJson('/v1/me', asPlayer($other))->assertOk();
    $bystander = securedPlayer('ada_1', 'ada@example.com');
    $sent = count($this->mail->sent);

    $this->postJson('/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(204)->assertNoContent();
    expect($this->mail->sent)->toHaveCount($sent); // same answer, no email

    $this->postJson('/v1/auth/password/forgot', ['email' => ' TOBI@example.com'])->assertStatus(204);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'tobi@example.com'])->assertStatus(204); // within a minute: no second email
    expect($this->mail->sent)->toHaveCount($sent + 1);
    $mail = $this->mail->sent[$sent];
    expect($mail['purpose'])->toBe('reset')->and($mail['email'])->toBe('tobi@example.com')->and($mail['username'])->toBe('tobi_codes');

    $this->postJson('/v1/auth/password/reset', ['email' => 'tobi@example.com', 'code' => wrongCode($mail['code']), 'password' => 'brand new pass'])
        ->assertStatus(400)->assertJsonPath('error.code', 'invalid_code');
    $this->postJson('/v1/auth/password/reset', ['email' => 'ada@example.com', 'code' => $mail['code'], 'password' => 'brand new pass'])
        ->assertStatus(400)->assertJsonPath('error.code', 'invalid_code');

    $r = $this->postJson('/v1/auth/password/reset', ['email' => 'tobi@example.com', 'code' => $mail['code'], 'password' => 'brand new pass'])->assertOk();
    expect(array_keys($r->json()))->toBe(['token', 'player'])->and($r->json('player.username'))->toBe('tobi_codes');

    $this->getJson('/v1/me', asPlayer($r->json('token')))->assertOk();
    $this->getJson('/v1/me', asPlayer($old))->assertStatus(401);
    $this->getJson('/v1/me', asPlayer($other))->assertStatus(401);
    $this->getJson('/v1/me', asPlayer($bystander))->assertOk()->assertJsonPath('username', 'ada_1');

    $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => PASSWORD])->assertStatus(401);
    $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => 'brand new pass'])->assertOk();
    $this->postJson('/v1/auth/password/reset', ['email' => 'tobi@example.com', 'code' => $mail['code'], 'password' => 'used twice 99'])
        ->assertStatus(400)->assertJsonPath('error.code', 'invalid_code'); // single use
});

it('changes a password and signs the other devices out', function () {
    $guest = guestToken('guest_only');
    $this->postJson('/v1/auth/password/change', ['currentPassword' => PASSWORD, 'newPassword' => 'brand new pass'], asPlayer($guest))
        ->assertStatus(409)->assertJsonPath('error.code', 'not_secured');

    $here = securedPlayer();
    $there = $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => PASSWORD])->json('token');
    $this->getJson('/v1/me', asPlayer($there))->assertOk();

    $this->postJson('/v1/auth/password/change', ['currentPassword' => 'not my password', 'newPassword' => 'brand new pass'], asPlayer($here))
        ->assertStatus(409)->assertExactJson(['error' => ['code' => 'wrong_password', 'message' => 'Your current password is wrong.']]);
    $this->postJson('/v1/auth/password/change', ['currentPassword' => PASSWORD, 'newPassword' => 'brand new pass'], asPlayer($here))
        ->assertStatus(204)->assertNoContent();

    $this->getJson('/v1/me', asPlayer($here))->assertOk();
    $this->getJson('/v1/me', asPlayer($there))->assertStatus(401);
    $this->getJson('/v1/me', asPlayer($guest))->assertOk();
    $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => PASSWORD])->assertStatus(401);
    $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes', 'password' => 'brand new pass'])->assertOk();
});

it('validates emails, codes and passwords', function () {
    $t = guestToken();
    $this->postJson('/v1/auth/account/email', ['email' => 'not-an-email'], asPlayer($t))->assertStatus(400)->assertJsonPath('error.details.0.field', 'email');
    $this->postJson('/v1/auth/account/email', [], asPlayer($t))->assertStatus(400);

    $r = $this->postJson('/v1/auth/account/confirm', ['email' => 'tobi@example.com', 'code' => '12345', 'password' => 'short'], asPlayer($t))->assertStatus(400);
    expect(array_column($r->json('error.details'), 'field'))->toBe(['code', 'password']);
    $this->postJson('/v1/auth/account/confirm', ['email' => 'tobi@example.com', 'code' => 123456, 'password' => PASSWORD], asPlayer($t))
        ->assertStatus(400)->assertJsonPath('error.details.0.field', 'code');
    $this->postJson('/v1/auth/account/confirm', ['email' => 'tobi@example.com', 'code' => '123456', 'password' => str_repeat('x', 73)], asPlayer($t))
        ->assertStatus(400)->assertJsonPath('error.details.0.field', 'password');

    $this->postJson('/v1/auth/login', ['identifier' => 'tobi_codes'])->assertStatus(400)->assertJsonPath('error.details.0.field', 'password');
    $this->postJson('/v1/auth/login', ['password' => PASSWORD])->assertStatus(400)->assertJsonPath('error.details.0.field', 'identifier');
    $this->postJson('/v1/auth/password/forgot', ['email' => 'nope'])->assertStatus(400);
    $this->postJson('/v1/auth/password/reset', ['email' => 'tobi@example.com', 'code' => 'abcdef', 'password' => PASSWORD])->assertStatus(400);
    $this->postJson('/v1/auth/password/change', ['currentPassword' => PASSWORD, 'newPassword' => 'short'], asPlayer($t))
        ->assertStatus(400)->assertJsonPath('error.details.0.field', 'newPassword');
});

it('limits sign-in, forgot and reset together per IP, apart from sign-up', function () {
    config(['statewars.rate_signin_per_min' => 2]);
    $this->postJson('/v1/auth/login', ['identifier' => 'nobody_here', 'password' => PASSWORD])->assertStatus(401);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(204);
    $this->postJson('/v1/auth/password/reset', ['email' => 'nobody@example.com', 'code' => '123456', 'password' => PASSWORD])->assertStatus(429)
        ->assertExactJson(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Wait a moment and try again.']])
        ->assertHeader('Retry-After');
    $this->postJson('/v1/auth/guest', ['username' => 'still_ok', 'stateCode' => 'KW'])->assertStatus(201);
});

it('emails the code as plain text through the configured mailer', function () {
    $mailer = app('mail.manager')->mailer('array');
    (new LaravelCodeMailer($mailer))->send('tobi@example.com', 'tobi_codes', '042917', CodeMailer::RESET, 10);

    $messages = $mailer->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $m = $messages[0]->getOriginalMessage();
    expect($m->getTo()[0]->getAddress())->toBe('tobi@example.com')
        ->and($m->getSubject())->toBe('042917 is your State Wars password reset code')
        ->and($m->getTextBody())->toContain('042917')->toContain('@tobi_codes')->toContain('10 minutes')
        ->and($m->getHtmlBody())->toBeNull();
});

// ---------- sign-up: every new account has a verified email and a password ----------

function signupBody(string $code, array $over = []): array
{
    return $over + ['username' => 'new_player', 'stateCode' => 'la', 'email' => 'new@example.com', 'code' => $code, 'password' => PASSWORD];
}

it('creates an account with a verified email and a password in two steps', function () {
    $this->postJson('/v1/auth/signup/email', ['email' => ' New@Example.com '])->assertStatus(204)->assertNoContent();
    expect($this->mail->sent)->toHaveCount(1);
    $mail = $this->mail->sent[0];
    expect($mail['email'])->toBe('new@example.com')->and($mail['purpose'])->toBe('signup')->and($mail['username'])->toBe('');
    expect(DB::table('users')->where('username', 'new_player')->exists())->toBeFalse(); // nothing exists before the code

    $r = $this->postJson('/v1/auth/signup', signupBody($mail['code']))->assertStatus(201);
    expect(array_keys($r->json()))->toBe(['token', 'player'])->and($r->json('player'))->toBe([
        'username' => 'new_player', 'stateCode' => 'LA', 'rank' => 'Olodo', 'pointsToNext' => 2000,
        'streak' => 0, 'bestStreak' => 0, 'battles' => 0, 'average' => 0,
    ]);
    $t = $r->json('token');
    $this->getJson('/v1/me', asPlayer($t))->assertOk()->assertJsonPath('username', 'new_player');
    $this->getJson('/v1/auth/account', asPlayer($t))->assertExactJson(['secured' => true, 'email' => 'new@example.com']);
    $this->postJson('/v1/auth/login', ['identifier' => 'new_player', 'password' => PASSWORD])->assertOk();
    $this->postJson('/v1/auth/login', ['identifier' => 'NEW@example.com', 'password' => PASSWORD])->assertOk();

    $this->postJson('/v1/auth/signup', signupBody($mail['code'], ['username' => 'second_try']))
        ->assertStatus(400)->assertJsonPath('error.code', 'invalid_code'); // single use
    expect(DB::table('users')->where('username', 'second_try')->exists())->toBeFalse();
});

it('refuses a bad sign-up without creating anything, and a taken name does not use up the code', function () {
    guestToken('taken_name');
    $this->postJson('/v1/auth/signup/email', ['email' => 'new@example.com'])->assertStatus(204);
    $code = $this->mail->lastCode();
    $users = DB::table('users')->count();

    $this->postJson('/v1/auth/signup', signupBody(wrongCode($code)))->assertStatus(400)->assertJsonPath('error.code', 'invalid_code');
    $this->postJson('/v1/auth/signup', signupBody($code, ['username' => 'Taken_Name']))->assertStatus(409)
        ->assertJsonPath('error.code', 'username_unavailable')->assertJsonPath('error.message', '@Taken_Name is taken.');
    $this->postJson('/v1/auth/signup', signupBody($code, ['stateCode' => 'ZZ']))->assertStatus(400)
        ->assertJsonPath('error.message', 'Pick one of the 36 states or the FCT.');
    $this->postJson('/v1/auth/signup', signupBody($code, ['email' => 'other@example.com']))->assertStatus(400)->assertJsonPath('error.code', 'invalid_code');
    expect(DB::table('users')->count())->toBe($users);

    $r = $this->postJson('/v1/auth/signup', ['username' => 'new_player', 'stateCode' => 'LA', 'email' => 'new@example.com'])->assertStatus(400);
    expect(array_column($r->json('error.details'), 'field'))->toBe(['code', 'password']);
    $this->postJson('/v1/auth/signup', signupBody($code, ['remind' => 'yes']))->assertStatus(400)->assertJsonPath('error.details.0.field', 'remind');

    $this->postJson('/v1/auth/signup', signupBody($code, ['remind' => false]))->assertStatus(201); // the code survived all of that
    expect(DB::table('users')->where('username', 'new_player')->value('remind'))->toBeFalse();
});

it('never says at step 1 that an email has an account, only to its owner at step 2', function () {
    securedPlayer();
    $sent = count($this->mail->sent);
    $this->postJson('/v1/auth/signup/email', ['email' => 'tobi@example.com'])->assertStatus(204)->assertNoContent();
    expect($this->mail->sent)->toHaveCount($sent + 1);
    $this->postJson('/v1/auth/signup/email', ['email' => 'tobi@example.com'])->assertStatus(429)
        ->assertExactJson(['error' => ['code' => 'rate_limited', 'message' => 'Wait a minute before asking for another code.']]);

    $users = DB::table('users')->count();
    $this->postJson('/v1/auth/signup', signupBody($this->mail->lastCode(), ['email' => 'tobi@example.com']))->assertStatus(409)
        ->assertExactJson(['error' => ['code' => 'email_taken', 'message' => 'That email already has an account. Sign in instead.']]);
    expect(DB::table('users')->count())->toBe($users);
});

it('closes guest sign-up unless GUEST_SIGNUP is on', function () {
    config(['statewars.guest_signup' => false]);
    $this->postJson('/v1/auth/guest', ['username' => 'no_email', 'stateCode' => 'KW'])->assertStatus(410)
        ->assertExactJson(['error' => ['code' => 'gone', 'message' => 'Guest sign-up is closed. Create an account with your email and a password.']]);
    expect(DB::table('users')->where('username', 'no_email')->exists())->toBeFalse();
});

// ---------- code emails go through the queue (SendCodeEmailJob) ----------

it('queues the code email, encrypted, on the mail queue; the worker sends it', function () {
    $this->app->forgetInstance(CodeMailer::class); // the real QueuedCodeMailer, not the recorder
    config(['queue.default' => 'redis', 'mail.default' => 'array']);

    $this->postJson('/v1/auth/signup/email', ['email' => 'new@example.com'])->assertStatus(204);
    $sent = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($sent())->toHaveCount(0); // the request did not send anything itself

    $queue = Queue::connection('redis');
    expect($queue->size('mail'))->toBe(1)->and($queue->size('default'))->toBe(0);
    $job = $queue->pop('mail');
    expect($job->getRawBody())->not->toContain('new@example.com'); // payload is encrypted: no address, so no code either
    expect($job->resolveName())->toBe(SendCodeEmailJob::class);

    $job->fire(); // what a queue worker does
    expect($sent())->toHaveCount(1);
    $mail = $sent()[0]->getOriginalMessage();
    expect($mail->getTo()[0]->getAddress())->toBe('new@example.com')->and($mail->getSubject())->toMatch('/^\d{6} is your State Wars sign-up code$/');

    $this->postJson('/v1/auth/signup', signupBody(substr($mail->getSubject(), 0, 6)))->assertStatus(201); // the emailed code is the real one
});

it('dispatches one job per code with the code\'s own expiry', function () {
    $this->app->forgetInstance(CodeMailer::class);
    Bus::fake([SendCodeEmailJob::class]);
    $now = Carbon::now()->getTimestampMs();
    $this->postJson('/v1/auth/signup/email', ['email' => 'new@example.com'])->assertStatus(204);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(204); // no account: no job

    Bus::assertDispatchedTimes(SendCodeEmailJob::class, 1);
    Bus::assertDispatched(SendCodeEmailJob::class, fn (SendCodeEmailJob $j) => $j->queue === 'mail'
        && $j instanceof Illuminate\Contracts\Queue\ShouldBeEncrypted
        && $j->email === 'new@example.com' && $j->purpose === 'signup' && $j->validMinutes === 10
        && $j->expiresAtMs >= $now + 600_000 && $j->expiresAtMs < $now + 660_000
        && $j->tries === 5 && $j->backoff() === [3, 5, 10, 20]);
});

it('does not send a code that has already expired', function () {
    config(['mail.default' => 'array']);
    $sent = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    $now = Carbon::now()->getTimestampMs();

    app()->call([new SendCodeEmailJob('late@example.com', 'tobi_codes', '042917', 'reset', 10, $now), 'handle']);
    expect($sent())->toHaveCount(0);
    app()->call([new SendCodeEmailJob('ontime@example.com', 'tobi_codes', '042917', 'reset', 10, $now + 60_000), 'handle']);
    expect($sent())->toHaveCount(1)->and($sent()[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('ontime@example.com');
});
