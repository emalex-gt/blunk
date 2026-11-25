<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant + branch link to the existing commercial catalog. No duplicate product data.
        Schema::create('fuel_product_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('label', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'branch_id', 'product_id'], 'fuel_config_scope_unique');
        });

        Schema::create('fuel_dispenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_product_id')->constrained('fuel_product_configs')->restrictOnDelete();
            $table->unsignedSmallInteger('pump_number');
            $table->decimal('liters', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('entry_mode', 10); // liters | amount
            $table->string('source', 20); // manual | automatic
            $table->string('source_key', 120);
            $table->char('request_fingerprint', 64);
            $table->string('external_device_id', 80)->nullable();
            $table->string('external_event_id', 120)->nullable();
            $table->timestamp('occurred_at')->nullable();
            // Independent lifecycles; NO sale, sale payment or FEL created in phase 1.
            $table->string('status', 20)->default('recorded');
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('invoice_status', 20)->default('not_requested');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'branch_id', 'source', 'source_key'], 'fuel_dispenses_origin_unique');
            $table->index(['business_id', 'branch_id', 'id'], 'fuel_dispenses_scope_cursor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_dispenses');
        Schema::dropIfExists('fuel_product_configs');
    }
};
