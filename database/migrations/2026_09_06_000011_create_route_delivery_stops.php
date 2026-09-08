<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_delivery_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_delivery_batch_pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name_snapshot')->nullable();
            $table->text('customer_address_snapshot')->nullable();
            $table->string('customer_phone_snapshot')->nullable();
            $table->integer('position')->nullable();
            $table->string('delivery_tracking_snapshot', 32)->default('in_app');
            $table->string('collection_responsibility_snapshot', 32);
            $table->string('status', 32)->default('pending');
            $table->string('not_delivered_reason_code', 32)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique('route_delivery_batch_pre_sale_id', 'route_delivery_stops_entry_unique');
            $table->index(['route_delivery_run_id', 'status', 'position', 'id'], 'route_delivery_stops_run_progress');
            $table->index(['business_id', 'branch_id', 'sale_id'], 'route_delivery_stops_scope_sale');
            $table->index(['route_delivery_batch_id', 'route_delivery_run_id'], 'route_delivery_stops_batch_run');
        });
        DB::statement("ALTER TABLE route_delivery_stops ADD CONSTRAINT route_delivery_stops_outcome_check CHECK ((status = 'pending' AND completed_by IS NULL AND completed_at IS NULL AND not_delivered_reason_code IS NULL) OR (status = 'delivered' AND completed_by IS NOT NULL AND completed_at IS NOT NULL AND not_delivered_reason_code IS NULL) OR (status = 'not_delivered' AND completed_by IS NOT NULL AND completed_at IS NOT NULL AND not_delivered_reason_code IN ('customer_absent', 'customer_rejected', 'address_issue', 'business_closed', 'damaged_goods', 'other') AND (not_delivered_reason_code <> 'other' OR delivery_notes IS NOT NULL AND btrim(delivery_notes) <> '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_delivery_stops');
    }
};
