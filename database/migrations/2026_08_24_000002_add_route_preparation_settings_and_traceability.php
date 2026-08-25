<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_settings')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('tenant_settings', 'route_pre_sale_stock_deduction_timing')) {
                    $table->string('route_pre_sale_stock_deduction_timing', 20)
                        ->default('invoice')
                        ->after('route_pre_sale_invoicing_mode');
                }
            });

            DB::table('tenant_settings')
                ->where('route_pre_sale_invoicing_mode', 'automatic')
                ->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        }

        if (Schema::hasTable('pre_sale_items')) {
            Schema::table('pre_sale_items', function (Blueprint $table) {
                if (! Schema::hasColumn('pre_sale_items', 'stock_deducted_quantity')) {
                    $table->decimal('stock_deducted_quantity', 14, 4)
                        ->default(0)
                        ->after('picked_quantity');
                }
            });
        }

        if (! Schema::hasTable('route_preparation_batches')) {
            Schema::create('route_preparation_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->foreignId('route_work_day_id')->constrained()->cascadeOnDelete();
                $table->foreignId('route_zone_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('prepared_at')->nullable();
                $table->string('status')->default('processing');
                $table->string('stock_deduction_timing', 20)->default('invoice');
                $table->string('invoicing_mode', 20)->default('manual');
                $table->unsignedInteger('total_pre_sales')->default(0);
                $table->unsignedInteger('total_items')->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->timestamp('documents_generated_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'branch_id']);
                $table->index('route_work_day_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('route_preparation_batch_pre_sales')) {
            Schema::create('route_preparation_batch_pre_sales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('route_preparation_batch_id')
                    ->constrained('route_preparation_batches')
                    ->cascadeOnDelete();
                $table->foreignId('pre_sale_id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('prepared');
                $table->unsignedInteger('total_items')->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique(['route_preparation_batch_id', 'pre_sale_id'], 'route_preparation_batch_pre_sales_unique');
                $table->index('pre_sale_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('route_preparation_batch_pre_sales');
        Schema::dropIfExists('route_preparation_batches');

        if (Schema::hasTable('pre_sale_items') && Schema::hasColumn('pre_sale_items', 'stock_deducted_quantity')) {
            Schema::table('pre_sale_items', function (Blueprint $table) {
                $table->dropColumn('stock_deducted_quantity');
            });
        }

        if (Schema::hasTable('tenant_settings') && Schema::hasColumn('tenant_settings', 'route_pre_sale_stock_deduction_timing')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->dropColumn('route_pre_sale_stock_deduction_timing');
            });
        }
    }
};
