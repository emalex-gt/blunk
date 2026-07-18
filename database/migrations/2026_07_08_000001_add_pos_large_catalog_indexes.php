<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            DB::statement('CREATE INDEX IF NOT EXISTS products_business_active_index ON products (business_id, is_active)');
            DB::statement('CREATE INDEX IF NOT EXISTS products_business_active_name_index ON products (business_id, is_active, name)');
            DB::statement('CREATE INDEX IF NOT EXISTS products_business_active_code_index ON products (business_id, is_active, code)');
            DB::statement('CREATE INDEX IF NOT EXISTS products_business_active_barcode_index ON products (business_id, is_active, barcode)');
        }

        if (Schema::hasTable('stock_reservations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS stock_reservations_business_branch_product_status_index ON stock_reservations (business_id, branch_id, product_id, status)');
        }

        if (Schema::hasTable('credit_receipt_lines')) {
            DB::statement('CREATE INDEX IF NOT EXISTS credit_receipt_lines_business_branch_product_status_index ON credit_receipt_lines (business_id, branch_id, product_id, status)');
        }

        if (Schema::hasTable('sale_items')) {
            DB::statement('CREATE INDEX IF NOT EXISTS sale_items_sale_id_index ON sale_items (sale_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS sale_items_product_id_index ON sale_items (product_id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_business_active_index');
        DB::statement('DROP INDEX IF EXISTS products_business_active_name_index');
        DB::statement('DROP INDEX IF EXISTS products_business_active_code_index');
        DB::statement('DROP INDEX IF EXISTS products_business_active_barcode_index');
        DB::statement('DROP INDEX IF EXISTS stock_reservations_business_branch_product_status_index');
        DB::statement('DROP INDEX IF EXISTS credit_receipt_lines_business_branch_product_status_index');
        DB::statement('DROP INDEX IF EXISTS sale_items_sale_id_index');
        DB::statement('DROP INDEX IF EXISTS sale_items_product_id_index');
    }
};
