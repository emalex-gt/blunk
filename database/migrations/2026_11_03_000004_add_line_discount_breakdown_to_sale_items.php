<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_items')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('sale_items', 'line_discount_amount')) {
                $table->decimal('line_discount_amount', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('sale_items', 'line_discount_reason')) {
                $table->text('line_discount_reason')->nullable();
            }
            if (! Schema::hasColumn('sale_items', 'general_discount_amount')) {
                $table->decimal('general_discount_amount', 12, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sale_items')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table): void {
            foreach (['line_discount_amount', 'line_discount_reason', 'general_discount_amount'] as $column) {
                if (Schema::hasColumn('sale_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
