<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'purchase_items.quantity',
        ] as $column) {
            [$table, $name] = explode('.', $column, 2);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE NUMERIC(18,4) USING {$name}::NUMERIC(18,4)");
        }
    }

    public function down(): void
    {
        foreach ([
            'purchase_items.quantity',
        ] as $column) {
            [$table, $name] = explode('.', $column, 2);
            $hasPrecisionBeyondTwoDecimals = DB::table($table)
                ->whereRaw("{$name} <> trunc({$name}, 2)")
                ->exists();

            if ($hasPrecisionBeyondTwoDecimals) {
                throw new RuntimeException("No se puede revertir {$table}.{$name}: contiene cantidades con más de dos decimales.");
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE NUMERIC(12,2) USING {$name}::NUMERIC(12,2)");
        }
    }
};
