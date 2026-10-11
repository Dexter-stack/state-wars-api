<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sanctum guest tokens (replaces the Node API's `sessions` table; see PLAN.md D1).
// uuidMorphs because users.id is a uuid. Only the SHA-256 of a token is stored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });

        // ON DELETE CASCADE like sessions.user_id (a polymorphic column can't have a plain FK in Laravel's builder)
        DB::statement('ALTER TABLE personal_access_tokens ADD CONSTRAINT personal_access_tokens_user_fk
            FOREIGN KEY (tokenable_id) REFERENCES users(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
