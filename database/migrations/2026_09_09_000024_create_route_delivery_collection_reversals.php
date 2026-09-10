<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_delivery_collection_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_collection_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_pending_collection_case_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('previous_case_resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('previous_case_resolved_at')->nullable();
            $table->string('reason_code', 48);
            $table->text('explanation');
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->string('cash_correction_type', 48);
            $table->foreignId('compensating_cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'branch_id', 'reversed_at'], 'route_delivery_reversals_scope_reversed');
            $table->index('route_pending_collection_case_id', 'route_delivery_reversals_case');
        });
        DB::statement("ALTER TABLE route_delivery_collection_reversals ADD CONSTRAINT route_delivery_reversals_reason_check CHECK (reason_code IN ('payment_recorded_by_mistake', 'wrong_customer', 'duplicate_collection', 'wrong_amount', 'other') AND btrim(explanation) <> '')");
        DB::statement("ALTER TABLE route_delivery_collection_reversals ADD CONSTRAINT route_delivery_reversals_cash_check CHECK ((cash_correction_type IN ('none', 'historical_closed_session_ledger') AND compensating_cash_movement_id IS NULL) OR (cash_correction_type = 'current_open_session_adjustment' AND compensating_cash_movement_id IS NOT NULL))");
        DB::statement("ALTER TABLE route_delivery_collection_reversals ADD CONSTRAINT route_delivery_reversals_case_check CHECK ((route_pending_collection_case_id IS NULL AND previous_case_resolved_by IS NULL AND previous_case_resolved_at IS NULL) OR (route_pending_collection_case_id IS NOT NULL AND previous_case_resolved_by IS NOT NULL AND previous_case_resolved_at IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX route_delivery_reversals_collection_unique ON route_delivery_collection_reversals (route_delivery_collection_id)');
        DB::statement('CREATE UNIQUE INDEX route_delivery_reversals_payment_unique ON route_delivery_collection_reversals (sale_payment_id)');
        DB::statement('CREATE UNIQUE INDEX route_delivery_reversals_cash_movement_unique ON route_delivery_collection_reversals (compensating_cash_movement_id) WHERE compensating_cash_movement_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX route_delivery_reversals_idempotency_unique ON route_delivery_collection_reversals (operation_idempotency_key_id) WHERE operation_idempotency_key_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('route_delivery_collection_reversals')->exists()) {
            throw new RuntimeException('Cannot roll back 3E-B1 reversal ledger while reversal rows exist.');
        }
        Schema::dropIfExists('route_delivery_collection_reversals');
    }
};
