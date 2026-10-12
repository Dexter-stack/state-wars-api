<?php

namespace App\Modules\Auth\Support\Contracts;

interface PasswordHasher
{
    public function hash(string $password): string;

    public function verify(string $password, string $hash): bool;
}
