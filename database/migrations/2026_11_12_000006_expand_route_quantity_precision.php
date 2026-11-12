<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = [
        'pre_sale_items.quantity',
        'pre_sale_items.picked_quantity',
        'pre_sale_items.stock_deducted_quantity',
        'stock_reservations.quantity',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            [$table, $name] = explode('.', $column, 2);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE NUMERIC(18,4) USING {$name}::NUMERIC(18,4)");
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            [$table, $name] = explode('.', $column, 2);

            if (DB::table($table)->whereRaw("{$name} > 9999999999.9999 OR {$name} < -9999999999.9999")->exists()) {
                throw new RuntimeException("No se puede revertir {$table}.{$name}: contiene cantidades fuera de NUMERIC(14,4).");
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE NUMERIC(14,4) USING {$name}::NUMERIC(14,4)");
        }
    }
};
