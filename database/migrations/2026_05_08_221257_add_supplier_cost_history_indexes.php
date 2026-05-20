<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->index(['business_id', 'product_id'], 'purchase_items_business_product_index');
            $table->index(['business_id', 'product_id', 'purchase_id'], 'purchase_items_business_product_purchase_index');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['business_id', 'supplier_id', 'created_at'], 'purchases_business_supplier_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropIndex('purchase_items_business_product_index');
            $table->dropIndex('purchase_items_business_product_purchase_index');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_business_supplier_created_index');
        });
    }
};
