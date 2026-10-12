<?php

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Support\Contracts\PasswordHasher;
use Illuminate\Contracts\Hashing\Hasher;

/** Laravel's configured hasher (bcrypt; cost from BCRYPT_ROUNDS). */
final class LaravelPasswordHasher implements PasswordHasher
{
    public function __construct(private readonly Hasher $hasher) {}

    public function hash(string $password): string
    {
        return $this->hasher->make($password);
    }

    public function verify(string $password, string $hash): bool
    {
        return $this->hasher->check($password, $hash);
    }
}
