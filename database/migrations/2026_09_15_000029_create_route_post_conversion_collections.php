<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_post_conversion_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_batch_pre_sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('pre_sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 32);
            $table->string('reference')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('collected_at');
            $table->string('cash_custody_policy_snapshot', 64);
            $table->string('custody_status', 32);
            $table->string('cash_posting_state', 32);
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->constrained()->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->string('status', 16)->default('captured');
            $table->timestamps();
            $table->index(['business_id', 'branch_id', 'sale_id'], 'route_post_conversion_collections_scope_sale');
            $table->unique('operation_idempotency_key_id', 'route_post_conversion_collections_idempotency_unique');
        });

        DB::statement("ALTER TABLE route_post_conversion_collections ADD CONSTRAINT route_post_conversion_collections_method_check CHECK (payment_method IN ('cash', 'card', 'transfer', 'check'))");
        DB::statement("ALTER TABLE route_post_conversion_collections ADD CONSTRAINT route_post_conversion_collections_status_check CHECK (status IN ('captured', 'reversed'))");
        DB::statement("ALTER TABLE route_post_conversion_collections ADD CONSTRAINT route_post_conversion_collections_cash_check CHECK ((payment_method <> 'cash' AND custody_status = 'not_applicable' AND cash_posting_state = 'not_applicable' AND cash_register_session_id IS NULL AND cash_movement_id IS NULL) OR (payment_method = 'cash' AND ((custody_status = 'held_by_collector' AND cash_posting_state = 'awaiting_physical_receipt' AND cash_register_session_id IS NULL AND cash_movement_id IS NULL) OR (custody_status = 'posted_to_branch_cash' AND cash_posting_state = 'posted_to_current_session' AND ((cash_register_session_id IS NOT NULL AND cash_movement_id IS NOT NULL) OR (cash_register_session_id IS NULL AND cash_movement_id IS NULL))))))");
        DB::statement("ALTER TABLE route_post_conversion_collections ADD CONSTRAINT route_post_conversion_collections_custody_policy_check CHECK (cash_custody_policy_snapshot IN ('collector_custody_until_settlement', 'immediate_branch_register'))");
        DB::statement("CREATE UNIQUE INDEX route_post_conversion_collections_sale_captured_unique ON route_post_conversion_collections (sale_id) WHERE status = 'captured'");
        DB::statement("CREATE UNIQUE INDEX route_post_conversion_collections_entry_captured_unique ON route_post_conversion_collections (route_delivery_batch_pre_sale_id) WHERE status = 'captured'");
    }

    public function down(): void
    {
        if (DB::table('route_post_conversion_collections')->exists()) {
            throw new RuntimeException('Cannot roll back post-conversion collections while financial trace rows exist.');
        }
        Schema::dropIfExists('route_post_conversion_collections');
    }
};
