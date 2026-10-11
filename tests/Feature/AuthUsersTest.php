<?php

use App\Modules\Users\Repositories\Contracts\UsersRepository;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    DatabaseSeeder::seed(20, 15, '2026-10-01', Carbon::now()->getTimestampMs());
});

function signUp(string $username = 'tobi_codes', string $state = 'kw'): array
{
    $r = test()->postJson('/v1/auth/guest', ['username' => $username, 'stateCode' => $state]);
    $r->assertStatus(201);

    return $r->json();
}

function bearer(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

it('checks usernames', function () {
    $this->postJson('/v1/usernames/check', ['username' => '  tobi_1  '])->assertOk()->assertExactJson(['available' => true]);
    $this->postJson('/v1/usernames/check', ['username' => 'a b'])->assertOk()
        ->assertExactJson(['available' => false, 'reason' => 'Use 3 to 16 letters, numbers, dots or underscores.']);
    $this->postJson('/v1/usernames/check', ['username' => 'Admin'])->assertExactJson(['available' => false, 'reason' => 'That name is reserved.']);
    $this->postJson('/v1/usernames/check', ['username' => 'SW_ADMIN'])->assertExactJson(['available' => false, 'reason' => '@SW_ADMIN is taken.']);
    $this->postJson('/v1/usernames/check', ['username' => str_repeat('x', 33)])->assertStatus(400)->assertJsonPath('error.code', 'bad_request');
    $this->postJson('/v1/usernames/check', [])->assertStatus(400);
});

it('signs up a guest and returns the exact player shape', function () {
    $out = signUp();
    expect($out['token'])->toBeString()->and(strlen($out['token']))->toBeGreaterThanOrEqual(20);
    expect($out['player'])->toBe([
        'username' => 'tobi_codes', 'stateCode' => 'KW', 'rank' => 'Olodo', 'pointsToNext' => 2000,
        'streak' => 0, 'bestStreak' => 0, 'battles' => 0, 'average' => 0,
    ]);
    $this->getJson('/v1/me', bearer($out['token']))->assertOk()->assertJsonPath('username', 'tobi_codes');
});

it('rejects duplicate usernames, bad states and bad bodies', function () {
    signUp();
    $this->postJson('/v1/auth/guest', ['username' => 'TOBI_codes', 'stateCode' => 'KW'])->assertStatus(409)
        ->assertJsonPath('error.code', 'username_unavailable')->assertJsonPath('error.message', '@TOBI_codes is taken.');
    $this->postJson('/v1/auth/guest', ['username' => 'ada', 'stateCode' => 'XX'])->assertStatus(400)
        ->assertJsonPath('error.message', 'Pick one of the 36 states or the FCT.');
    $this->postJson('/v1/auth/guest', ['username' => 'ada', 'stateCode' => 'KWA'])->assertStatus(400)
        ->assertJsonPath('error.details.0.field', 'stateCode');
    $this->postJson('/v1/auth/guest', ['username' => 'ada', 'stateCode' => 'KW', 'remind' => 'yes'])->assertStatus(400);
    $this->postJson('/v1/auth/guest', ['username' => 'ada', 'stateCode' => 'KW', 'remind' => false])->assertStatus(201);
});

it('requires a valid bearer token', function () {
    $this->getJson('/v1/me')->assertStatus(401)->assertExactJson(['error' => ['code' => 'unauthorized', 'message' => 'Sign in to continue.']]);
    $this->getJson('/v1/me', bearer('short'))->assertStatus(401);
    $this->getJson('/v1/me', bearer(str_repeat('x', 40)))->assertStatus(401)
        ->assertJsonPath('error.message', 'Your session has ended. Sign in again.');
    $out = signUp();
    [$id, $secret] = explode('|', $out['token']);
    $this->getJson('/v1/me', bearer($id.'|'.strrev($secret)))->assertStatus(401);
});

it('logs out and the token stops working at once', function () {
    $t = signUp()['token'];
    $this->getJson('/v1/me', bearer($t))->assertOk(); // now cached
    $this->postJson('/v1/auth/logout', [], bearer($t))->assertStatus(204)->assertNoContent();
    $this->getJson('/v1/me', bearer($t))->assertStatus(401);
});

it('updates remind and allows one free state correction, then a cooldown', function () {
    $t = signUp()['token'];
    $this->patchJson('/v1/me', [], bearer($t))->assertStatus(400)
        ->assertJsonPath('error.details.0.message', 'Send stateCode or remind.');
    $this->patchJson('/v1/me', ['remind' => false], bearer($t))->assertOk();
    $this->patchJson('/v1/me', ['stateCode' => 'la'], bearer($t))->assertOk()->assertJsonPath('stateCode', 'LA');
    $this->patchJson('/v1/me', ['stateCode' => 'LA'], bearer($t))->assertOk(); // same state: no-op
    $r = $this->patchJson('/v1/me', ['stateCode' => 'OY'], bearer($t))->assertStatus(409)->assertJsonPath('error.code', 'state_change_cooldown');
    expect($r->json('error.message'))->toStartWith('You can change your state again on ');

    $this->travel(31)->days();
    $this->patchJson('/v1/me', ['stateCode' => 'OY'], bearer($t))->assertOk()->assertJsonPath('stateCode', 'OY');
});

it('updates the streak atomically and exactly as the Node SQL', function () {
    $t = signUp()['token'];
    $id = DB::table('users')->where('username', 'tobi_codes')->value('id');
    $repo = app(UsersRepository::class);

    expect($repo->applyBattleResult($id, '2026-10-20', 900))->toBe(['streak' => 1, 'bestStreak' => 1, 'points' => 900]);
    expect($repo->applyBattleResult($id, '2026-10-20', 0))->toBe(['streak' => 1, 'bestStreak' => 1, 'points' => 900]); // same day
    expect($repo->applyBattleResult($id, '2026-10-21', 100))->toBe(['streak' => 2, 'bestStreak' => 2, 'points' => 1000]);
    expect($repo->applyBattleResult($id, '2026-10-23', 100))->toBe(['streak' => 1, 'bestStreak' => 2, 'points' => 1100]); // gap
    expect($repo->applyBattleResult($id, '2026-10-22', 100)['streak'])->toBe(1); // late settle of an older day never moves the date back
    expect(DB::table('users')->where('id', $id)->value('last_played_date'))->toBe('2026-10-23');

    $this->travelTo(Carbon::parse('2026-10-24T12:00:00+01:00'));
    $this->getJson('/v1/me', bearer($t))->assertJsonPath('streak', 1)->assertJsonPath('bestStreak', 2);
    $this->travelTo(Carbon::parse('2026-10-26T12:00:00+01:00'));
    $this->getJson('/v1/me', bearer($t))->assertJsonPath('streak', 0); // not played yesterday or today
});
