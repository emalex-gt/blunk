<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum is already in composer.json. Avoid colliding with any prior
        // package/published migration on installations that already use tokens.
        if (Schema::hasTable('personal_access_tokens')) return;

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberate no-op: this table may have existed before Fuel and may
        // contain tokens belonging to other integrations. Never drop them.
    }
};
