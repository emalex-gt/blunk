<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_closing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->jsonb('snapshot');
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['business_id', 'branch_id']);
        });

        Schema::create('cash_register_closing_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('original_cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
            $table->foreignId('correction_cash_register_session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('correction_type');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 14, 2);
            $table->timestamp('occurred_at');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 2000)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'correction_type'], 'cash_closing_correction_source_unique');
            $table->index(['business_id', 'branch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_closing_corrections');
        Schema::dropIfExists('cash_register_closing_snapshots');
    }
};
