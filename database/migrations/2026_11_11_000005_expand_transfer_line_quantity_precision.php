<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_transfer_lines ALTER COLUMN quantity TYPE NUMERIC(18,4) USING quantity::NUMERIC(18,4)');
    }

    public function down(): void
    {
        if (DB::table('inventory_transfer_lines')->whereRaw('quantity <> trunc(quantity, 2)')->exists()) {
            throw new RuntimeException('No se puede revertir inventory_transfer_lines.quantity: contiene cantidades con más de dos decimales.');
        }

        DB::statement('ALTER TABLE inventory_transfer_lines ALTER COLUMN quantity TYPE NUMERIC(12,2) USING quantity::NUMERIC(12,2)');
    }
};
