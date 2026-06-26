<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
            $table->index('branch_id');
            $table->index('assigned_user_id');
            $table->index(['business_id', 'branch_id', 'name']);
        });

        Schema::create('route_zone_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('visit_order')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
            $table->index('route_zone_id');
            $table->index('customer_id');
            $table->unique(['route_zone_id', 'customer_id']);
        });

        Schema::create('route_work_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status')->default('open');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('branch_id');
            $table->index('route_zone_id');
            $table->index('seller_id');
            $table->index('work_date');
            $table->index('status');
            $table->unique(['business_id', 'route_zone_id', 'seller_id', 'work_date'], 'route_work_days_unique_day');
        });

        Schema::create('route_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_work_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('visit_order')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('route_work_day_id');
            $table->index('route_zone_id');
            $table->index('customer_id');
            $table->index('seller_id');
            $table->index('status');
            $table->unique(['route_work_day_id', 'customer_id']);
        });

        Schema::create('pre_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_work_day_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('branch_id');
            $table->index('route_work_day_id');
            $table->index('route_visit_id');
            $table->index('customer_id');
            $table->index('seller_id');
            $table->index('status');
        });

        Schema::create('pre_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pre_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('pre_sale_id');
            $table->index('product_id');
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('branch_id');
            $table->index('warehouse_id');
            $table->index('product_id');
            $table->index(['source_type', 'source_id']);
            $table->index(['source_type', 'source_item_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('pre_sale_items');
        Schema::dropIfExists('pre_sales');
        Schema::dropIfExists('route_visits');
        Schema::dropIfExists('route_work_days');
        Schema::dropIfExists('route_zone_customers');
        Schema::dropIfExists('route_zones');
    }
};
