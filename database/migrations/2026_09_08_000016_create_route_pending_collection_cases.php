<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_pending_collection_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete()->unique();
            $table->foreignId('pre_sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_delivery_batch_pre_sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_external_delivery_reconciliation_item_id')->nullable()->constrained()->restrictOnDelete()->unique();
            $table->foreignId('route_delivery_stop_id')->nullable()->constrained()->restrictOnDelete()->unique();
            $table->string('delivery_origin', 32);
            $table->foreignId('original_delivery_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('resolution_route_delivery_collection_id')->nullable()->constrained('route_delivery_collections')->restrictOnDelete()->unique();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('not_applicable_at')->nullable();
            $table->foreignId('not_applicable_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('not_applicable_reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'branch_id', 'status', 'next_follow_up_at'], 'route_pending_cases_scope_follow_up');
            $table->index(['business_id', 'branch_id', 'status', 'opened_at'], 'route_pending_cases_scope_opened');
            $table->index(['assigned_to', 'status', 'next_follow_up_at'], 'route_pending_cases_assignee_follow_up');
        });

        DB::statement("ALTER TABLE route_pending_collection_cases ADD CONSTRAINT route_pending_cases_origin_check CHECK ((delivery_origin = 'external_reconciliation' AND route_external_delivery_reconciliation_item_id IS NOT NULL AND route_delivery_stop_id IS NULL) OR (delivery_origin = 'in_app_stop' AND route_external_delivery_reconciliation_item_id IS NULL AND route_delivery_stop_id IS NOT NULL))");
        DB::statement("ALTER TABLE route_pending_collection_cases ADD CONSTRAINT route_pending_cases_status_check CHECK ((status = 'open' AND resolved_at IS NULL AND resolved_by IS NULL AND resolution_route_delivery_collection_id IS NULL) OR (status = 'resolved' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND resolution_route_delivery_collection_id IS NOT NULL) OR (status = 'not_applicable' AND resolved_at IS NULL AND resolved_by IS NULL AND resolution_route_delivery_collection_id IS NULL AND not_applicable_at IS NOT NULL AND not_applicable_by IS NOT NULL AND not_applicable_reason IS NOT NULL AND btrim(not_applicable_reason) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_pending_collection_cases');
    }
};
