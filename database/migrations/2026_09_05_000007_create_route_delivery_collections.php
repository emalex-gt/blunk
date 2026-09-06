<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_delivery_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_external_delivery_reconciliation_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 32);
            $table->string('reference')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('collected_at');
            $table->string('cash_custody_policy_snapshot', 64);
            $table->string('custody_status', 32);
            $table->string('cash_posting_state', 32);
            $table->timestamp('physical_branch_receipt_confirmed_at')->nullable();
            $table->foreignId('physical_branch_receipt_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->constrained()->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $table->unique('sale_id', 'route_delivery_collections_sale_unique');
            $table->unique('route_external_delivery_reconciliation_item_id', 'route_delivery_collections_item_unique');
            $table->unique('cash_movement_id', 'route_delivery_collections_cash_movement_unique');
            $table->index(['business_id', 'branch_id', 'sale_id'], 'route_delivery_collections_scope_sale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_delivery_collections');
    }
};
