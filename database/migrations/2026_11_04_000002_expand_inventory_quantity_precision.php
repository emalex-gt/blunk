<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'products.stock', 'products.min_stock', 'product_branch_stocks.stock',
            'stock_movements.quantity', 'stock_movements.previous_stock', 'stock_movements.new_stock',
            'purchase_items.quantity', 'inventory_transfer_lines.quantity',
        ] as $column) {
            [$table, $name] = explode('.', $column, 2);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE NUMERIC(18,4) USING {$name}::numeric");
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: reducing precision can destroy inventory history.
    }
};
