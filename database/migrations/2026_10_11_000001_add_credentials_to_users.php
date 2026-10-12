<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Sign-in and recovery: a guest can attach a verified email and a password to their account.
// Both stay NULL for guests who never do. email is only written after its code was confirmed, in lower case.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
              ADD COLUMN email         text,
              ADD COLUMN password_hash text,
              ADD CONSTRAINT users_credentials_together CHECK ((email IS NULL) = (password_hash IS NULL));
            CREATE UNIQUE INDEX users_email_lower ON users (lower(email)) WHERE email IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS users_email_lower;
            ALTER TABLE users
              DROP CONSTRAINT IF EXISTS users_credentials_together,
              DROP COLUMN IF EXISTS password_hash,
              DROP COLUMN IF EXISTS email;
        SQL);
    }
};
