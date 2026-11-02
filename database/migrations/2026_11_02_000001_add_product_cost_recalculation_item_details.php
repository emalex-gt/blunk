<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = 'product_cost_recalculation_items';

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            if (! Schema::hasColumn('product_cost_recalculation_items', 'physical_stock')) {
                $table->decimal('physical_stock', 20, 5)->nullable();
            }

            if (! Schema::hasColumn('product_cost_recalculation_items', 'covered_quantity')) {
                $table->decimal('covered_quantity', 20, 5)->nullable();
            }

            if (! Schema::hasColumn('product_cost_recalculation_items', 'missing_quantity')) {
                $table->decimal('missing_quantity', 20, 5)->nullable();
            }

            if (! Schema::hasColumn('product_cost_recalculation_items', 'selected_acquisitions')) {
                $table->json('selected_acquisitions')->nullable();
            }

            if (! Schema::hasColumn('product_cost_recalculation_items', 'calculation_method')) {
                $table->string('calculation_method', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: these are immutable audit fields and
        // the initial migration may already provide some on newer installs.
    }
};
