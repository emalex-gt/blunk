<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operation_idempotency_keys')) {
            return;
        }

        Schema::create('operation_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation_type', 80);
            $table->string('idempotency_key', 120);
            $table->string('request_hash', 64);
            $table->string('status', 30)->default('processing');
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['business_id', 'branch_id', 'user_id', 'operation_type', 'idempotency_key'],
                'operation_idempotency_unique'
            );
            $table->index(['business_id', 'operation_type', 'status']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_idempotency_keys');
    }
};
