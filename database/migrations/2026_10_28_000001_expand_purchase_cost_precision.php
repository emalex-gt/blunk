<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('total', 15, 5)->change();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 5)->change();
            $table->decimal('previous_cost', 15, 5)->change();
            $table->decimal('new_average_cost', 15, 5)->change();
            $table->decimal('total', 15, 5)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 5)->default(0)->change();
        });

        Schema::table('historical_product_purchase_costs', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 5)->change();
        });
    }

    public function down(): void
    {
        Schema::table('historical_product_purchase_costs', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 4)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->default(0)->change();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->change();
            $table->decimal('previous_cost', 12, 2)->change();
            $table->decimal('new_average_cost', 12, 2)->change();
            $table->decimal('total', 12, 2)->change();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('total', 12, 2)->change();
        });
    }
};
