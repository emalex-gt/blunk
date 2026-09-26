<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_operation_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_delivery_batch_pre_sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_external_delivery_reconciliation_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('route_delivery_stop_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('goods_received_at')->nullable();
            $table->foreignId('goods_received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->timestamps();

            $table->unique(['business_id', 'branch_id', 'idempotency_key'], 'route_operation_returns_idempotency_unique');
            $table->index(['business_id', 'branch_id', 'sale_id'], 'route_operation_returns_sale_scope');
        });

        DB::statement("ALTER TABLE route_operation_returns ADD CONSTRAINT route_operation_returns_source_check CHECK ((route_external_delivery_reconciliation_item_id IS NOT NULL AND route_delivery_stop_id IS NULL) OR (route_external_delivery_reconciliation_item_id IS NULL AND route_delivery_stop_id IS NOT NULL))");
        DB::statement("ALTER TABLE route_operation_returns ADD CONSTRAINT route_operation_returns_status_check CHECK ((status = 'pending' AND completed_at IS NULL AND completed_by IS NULL) OR (status = 'completed' AND goods_received_at IS NOT NULL AND goods_received_by IS NOT NULL AND completed_at IS NOT NULL AND completed_by IS NOT NULL) OR (status = 'cancelled' AND completed_at IS NULL AND completed_by IS NULL))");
        DB::statement("CREATE UNIQUE INDEX route_operation_returns_active_sale_unique ON route_operation_returns (sale_id) WHERE status IN ('pending', 'completed')");

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('route_operation_return_id')->nullable()->constrained('route_operation_returns')->restrictOnDelete();
            $table->index('route_operation_return_id', 'stock_movements_route_operation_return_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_route_operation_return_index');
            $table->dropConstrainedForeignId('route_operation_return_id');
        });
        Schema::dropIfExists('route_operation_returns');
    }
};
