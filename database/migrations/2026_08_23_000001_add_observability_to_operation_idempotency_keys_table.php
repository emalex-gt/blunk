<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_idempotency_keys', function (Blueprint $table) {
            $table->unsignedInteger('replay_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->timestamp('last_replayed_at')->nullable();
            $table->timestamp('last_conflict_at')->nullable();
            $table->text('last_error')->nullable();
            $table->index(['business_id', 'status', 'locked_at'], 'idempotency_health_status_locked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('operation_idempotency_keys', function (Blueprint $table) {
            $table->dropIndex('idempotency_health_status_locked_idx');
            $table->dropColumn([
                'replay_count',
                'conflict_count',
                'last_replayed_at',
                'last_conflict_at',
                'last_error',
            ]);
        });
    }
};
