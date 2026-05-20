<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'discount_type')) {
                $table->string('discount_type')->nullable();
            }

            if (! Schema::hasColumn('sales', 'discount_value')) {
                $table->decimal('discount_value', 12, 2)->default(0);
            }

            if (! Schema::hasColumn('sales', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0);
            }

            if (! Schema::hasColumn('sales', 'discount_reason')) {
                $table->text('discount_reason')->nullable();
            }

            if (! Schema::hasColumn('sales', 'subtotal_before_discount')) {
                $table->decimal('subtotal_before_discount', 12, 2)->nullable();
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0);
            }

            if (! Schema::hasColumn('sale_items', 'total_before_discount')) {
                $table->decimal('total_before_discount', 12, 2)->nullable();
            }

            if (! Schema::hasColumn('sale_items', 'total_after_discount')) {
                $table->decimal('total_after_discount', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            foreach (['discount_amount', 'total_before_discount', 'total_after_discount'] as $column) {
                if (Schema::hasColumn('sale_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            foreach (['discount_type', 'discount_value', 'discount_amount', 'discount_reason', 'subtotal_before_discount'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
