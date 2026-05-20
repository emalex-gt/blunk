<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products ALTER COLUMN stock TYPE NUMERIC(12,2) USING stock::numeric');
        DB::statement('ALTER TABLE products ALTER COLUMN min_stock TYPE NUMERIC(12,2) USING min_stock::numeric');
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN quantity TYPE NUMERIC(12,2) USING quantity::numeric');
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN previous_stock TYPE NUMERIC(12,2) USING previous_stock::numeric');
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN new_stock TYPE NUMERIC(12,2) USING new_stock::numeric');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products ALTER COLUMN stock TYPE INTEGER USING ROUND(stock)::integer');
        DB::statement('ALTER TABLE products ALTER COLUMN min_stock TYPE INTEGER USING ROUND(min_stock)::integer');
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN quantity TYPE INTEGER USING ROUND(quantity)::integer');
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN previous_stock TYPE INTEGER USING ROUND(previous_stock)::integer');
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN new_stock TYPE INTEGER USING ROUND(new_stock)::integer');
    }
};
