<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sale_items ALTER COLUMN quantity TYPE NUMERIC(18,4) USING quantity::NUMERIC(18,4)');
    }

    public function down(): void
    {
        if (DB::table('sale_items')->whereRaw('quantity <> trunc(quantity)')->exists()) {
            throw new RuntimeException('No se puede revertir sale_items.quantity: contiene cantidades fraccionarias.');
        }

        DB::statement('ALTER TABLE sale_items ALTER COLUMN quantity TYPE INTEGER USING quantity::INTEGER');
    }
};
