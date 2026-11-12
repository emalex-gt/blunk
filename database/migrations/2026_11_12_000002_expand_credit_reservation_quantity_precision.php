<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'credit_receipt_lines.quantity',
            'credit_receipt_lines.qty_reserved',
            'credit_receipt_lines.qty_invoiced',
            'credit_receipt_lines.qty_cancelled',
            'credit_receipt_lines.qty_pending',
            'credit_receipt_line_invoice.quantity',
        ] as $column) {
            [$table, $name] = explode('.', $column, 2);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE NUMERIC(18,4) USING {$name}::NUMERIC(18,4)");
        }
    }

    public function down(): void
    {
        foreach ([
            'credit_receipt_lines.quantity',
            'credit_receipt_lines.qty_reserved',
            'credit_receipt_lines.qty_invoiced',
            'credit_receipt_lines.qty_cancelled',
            'credit_receipt_lines.qty_pending',
            'credit_receipt_line_invoice.quantity',
        ] as $column) {
            [$table, $name] = explode('.', $column, 2);

            if (DB::table($table)->whereRaw("{$name} <> trunc({$name})")->exists()) {
                throw new RuntimeException("No se puede revertir {$table}.{$name}: contiene cantidades fraccionarias.");
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE INTEGER USING {$name}::INTEGER");
        }
    }
};
