<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_product_purchase_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('acquired_at');
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->string('supplier_invoice_number', 100)->nullable();
            $table->string('source_system', 64);
            $table->string('source_record_id', 191);
            $table->timestamp('imported_at')->useCurrent();

            $table->unique(['business_id', 'source_system', 'source_record_id'], 'hist_ppc_business_source_record_unique');
            $table->index(['business_id', 'product_id', 'acquired_at'], 'hist_ppc_business_product_acquired_idx');
            $table->index(['business_id', 'supplier_id', 'acquired_at'], 'hist_ppc_business_supplier_acquired_idx');
            $table->index(['business_id', 'branch_id'], 'hist_ppc_business_branch_idx');
            $table->index(['business_id', 'source_system'], 'hist_ppc_business_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_product_purchase_costs');
    }
};
