<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->decimal('expected_amount', 14, 2)->default(0);
            $table->decimal('received_amount', 14, 2)->nullable();
            $table->decimal('difference_amount', 14, 2)->nullable();
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('operation_idempotency_key_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'branch_id', 'collector_user_id', 'status'], 'route_cash_settlements_scope_status');
        });
        DB::statement("ALTER TABLE route_cash_settlements ADD CONSTRAINT route_cash_settlements_state_check CHECK ((status = 'draft' AND cash_movement_id IS NULL AND confirmed_at IS NULL AND cancelled_at IS NULL) OR (status = 'confirmed' AND cash_movement_id IS NOT NULL AND cash_register_session_id IS NOT NULL AND received_by IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND received_amount = expected_amount AND difference_amount = 0) OR (status = 'cancelled' AND cash_movement_id IS NULL AND cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND btrim(cancellation_reason) <> ''))");
    }
    public function down(): void { Schema::dropIfExists('route_cash_settlements'); }
};
