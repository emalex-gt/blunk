<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_external_delivery_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique('route_delivery_batch_id', 'route_external_reconciliations_batch_unique');
            $table->index(['business_id', 'branch_id', 'route_delivery_batch_id'], 'route_external_reconciliations_scope');
        });

        Schema::create('route_external_delivery_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_external_delivery_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_batch_pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_tracking_snapshot', 32);
            $table->string('collection_responsibility_snapshot', 32);
            $table->string('delivery_status', 32);
            $table->string('not_delivered_reason', 32)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reconciled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reconciled_at');
            $table->timestamps();
            $table->unique('route_delivery_batch_pre_sale_id', 'route_external_reconciliation_items_entry_unique');
            $table->index(['business_id', 'branch_id', 'sale_id'], 'route_external_reconciliation_items_scope_sale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_external_delivery_reconciliation_items');
        Schema::dropIfExists('route_external_delivery_reconciliations');
    }
};
