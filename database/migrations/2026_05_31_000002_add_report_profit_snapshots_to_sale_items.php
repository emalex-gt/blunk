<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'total_cost')) {
                $table->decimal('total_cost', 12, 2)->nullable()->after('unit_cost');
            }

            if (! Schema::hasColumn('sale_items', 'profit_amount')) {
                $table->decimal('profit_amount', 12, 2)->nullable()->after('total_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            foreach (['profit_amount', 'total_cost'] as $column) {
                if (Schema::hasColumn('sale_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
