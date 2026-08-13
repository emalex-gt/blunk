<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            DB::statement('CREATE INDEX IF NOT EXISTS products_business_name_idx ON products (business_id, name)');
            DB::statement('CREATE INDEX IF NOT EXISTS products_business_code_idx ON products (business_id, code)');
            DB::statement('CREATE INDEX IF NOT EXISTS products_business_barcode_idx ON products (business_id, barcode)');
        }

        if (Schema::hasTable('product_branch_stocks')) {
            DB::statement('CREATE INDEX IF NOT EXISTS product_branch_stocks_business_branch_product_idx ON product_branch_stocks (business_id, branch_id, product_id)');
        }

        if (Schema::hasTable('stock_reservations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS stock_reservations_business_branch_product_status_source_idx ON stock_reservations (business_id, branch_id, product_id, status, source_type)');
        }

        if (Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'branch_id')) {
            DB::statement('CREATE INDEX IF NOT EXISTS stock_movements_business_branch_product_idx ON stock_movements (business_id, branch_id, product_id)');
        }

        if (Schema::hasTable('categories')) {
            DB::statement('CREATE INDEX IF NOT EXISTS categories_business_id_idx ON categories (business_id)');
        }

        if (Schema::hasTable('brands')) {
            DB::statement('CREATE INDEX IF NOT EXISTS brands_business_id_idx ON brands (business_id)');
        }

        if (Schema::hasTable('product_locations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS product_locations_business_id_idx ON product_locations (business_id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_business_name_idx');
        DB::statement('DROP INDEX IF EXISTS products_business_code_idx');
        DB::statement('DROP INDEX IF EXISTS products_business_barcode_idx');
        DB::statement('DROP INDEX IF EXISTS product_branch_stocks_business_branch_product_idx');
        DB::statement('DROP INDEX IF EXISTS stock_reservations_business_branch_product_status_source_idx');
        DB::statement('DROP INDEX IF EXISTS stock_movements_business_branch_product_idx');
        DB::statement('DROP INDEX IF EXISTS categories_business_id_idx');
        DB::statement('DROP INDEX IF EXISTS brands_business_id_idx');
        DB::statement('DROP INDEX IF EXISTS product_locations_business_id_idx');
    }
};
