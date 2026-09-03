<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_sales', 'payment_method')) {
                $table->string('payment_method', 20)->nullable()->after('total');
                $table->timestamp('payment_method_set_at')->nullable()->after('payment_method');
                $table->foreignId('payment_method_set_by')->nullable()->after('payment_method_set_at')->constrained('users')->nullOnDelete();
                $table->index(['business_id', 'payment_method']);
            }
        });

        Schema::create('route_delivery_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_work_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('status')->default('processing');
            $table->string('stock_deduction_timing', 20)->default('invoice');
            $table->string('invoicing_mode', 20)->default('manual');
            $table->boolean('fel_automation_enabled')->default(false);
            $table->unsignedInteger('total_pre_sales')->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'branch_id']);
            $table->index('route_work_day_id');
            $table->index('status');
        });

        Schema::create('route_delivery_batch_pre_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_delivery_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('delivered');
            $table->string('payment_method', 20);
            $table->string('fel_dispatch_status', 20)->default('not_requested');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['route_delivery_batch_id', 'pre_sale_id'], 'route_delivery_batch_pre_sales_unique');
            $table->unique('pre_sale_id', 'route_delivery_batch_pre_sales_pre_sale_unique');
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_delivery_batch_pre_sales');
        Schema::dropIfExists('route_delivery_batches');

        Schema::table('pre_sales', function (Blueprint $table) {
            if (Schema::hasColumn('pre_sales', 'payment_method')) {
                $table->dropIndex(['business_id', 'payment_method']);
                $table->dropConstrainedForeignId('payment_method_set_by');
                $table->dropColumn(['payment_method', 'payment_method_set_at']);
            }
        });
    }
};
