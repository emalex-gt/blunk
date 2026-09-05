<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('route_pre_sale_collections')) {
            return;
        }

        Schema::create('route_pre_sale_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_work_day_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 32);
            $table->string('reference')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('collected_at');
            $table->string('status', 20)->default('captured');
            $table->string('custody_status', 32)->default('not_applicable');
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('operation_idempotency_key_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'branch_id', 'pre_sale_id', 'status'], 'route_pre_sale_collections_scope_status');
            $table->index(['route_work_day_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_pre_sale_collections');
    }
};
