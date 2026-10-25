<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id(); $table->foreignId('business_id')->constrained()->cascadeOnDelete(); $table->unsignedBigInteger('business_number');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('created_by')->constrained('users');
            $table->string('status', 30)->default('open'); $table->timestamp('issued_at'); $table->date('valid_until'); $table->text('note')->nullable();
            $table->string('customer_name')->nullable(); $table->string('customer_commercial_name')->nullable(); $table->string('customer_doc_type')->nullable(); $table->string('customer_doc_number')->nullable(); $table->string('customer_address')->nullable(); $table->text('customer_address_reference')->nullable(); $table->string('customer_postal_code')->nullable(); $table->string('customer_municipality')->nullable(); $table->string('customer_department')->nullable(); $table->string('customer_country')->nullable(); $table->string('customer_phone')->nullable();
            $table->decimal('total', 12, 2); $table->decimal('subtotal_before_discount', 12, 2)->nullable(); $table->string('discount_type')->nullable(); $table->decimal('discount_value', 12, 2)->default(0); $table->decimal('discount_amount', 12, 2)->default(0); $table->text('discount_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable(); $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete(); $table->text('cancellation_reason')->nullable(); $table->timestamp('converted_at')->nullable(); $table->timestamps();
            $table->unique(['business_id', 'business_number']); $table->index(['business_id', 'status']); $table->index(['business_id', 'valid_until']); $table->index(['business_id', 'customer_id']); $table->index(['business_id', 'branch_id', 'status']);
        });
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('business_id')->constrained()->cascadeOnDelete(); $table->foreignId('quotation_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('price_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name'); $table->string('product_code')->nullable(); $table->string('product_barcode')->nullable(); $table->string('price_type_name')->nullable(); $table->unsignedInteger('quantity'); $table->decimal('unit_price', 12, 2); $table->decimal('original_price', 12, 2)->nullable(); $table->string('price_source')->nullable(); $table->boolean('manual_price')->default(false); $table->decimal('discount_amount', 12, 2)->default(0); $table->decimal('total_before_discount', 12, 2)->nullable(); $table->decimal('total_after_discount', 12, 2)->nullable(); $table->decimal('total', 12, 2); $table->timestamps();
            $table->index(['quotation_id']); $table->index(['business_id', 'product_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('quotation_items'); Schema::dropIfExists('quotations'); }
};
