<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_product_prices')) {
            DB::statement('CREATE INDEX IF NOT EXISTS branch_product_prices_business_branch_index ON branch_product_prices (business_id, branch_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS branch_product_prices_product_id_index ON branch_product_prices (product_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS branch_product_prices_price_type_id_index ON branch_product_prices (price_type_id)');
        }

        if (Schema::hasTable('branch_product_variant_prices')) {
            DB::statement('CREATE INDEX IF NOT EXISTS branch_variant_prices_business_branch_index ON branch_product_variant_prices (business_id, branch_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS branch_variant_prices_variant_id_index ON branch_product_variant_prices (product_variant_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS branch_variant_prices_price_type_id_index ON branch_product_variant_prices (price_type_id)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('branch_product_prices')) {
            DB::statement('DROP INDEX IF EXISTS branch_product_prices_business_branch_index');
            DB::statement('DROP INDEX IF EXISTS branch_product_prices_product_id_index');
            DB::statement('DROP INDEX IF EXISTS branch_product_prices_price_type_id_index');
        }

        if (Schema::hasTable('branch_product_variant_prices')) {
            DB::statement('DROP INDEX IF EXISTS branch_variant_prices_business_branch_index');
            DB::statement('DROP INDEX IF EXISTS branch_variant_prices_variant_id_index');
            DB::statement('DROP INDEX IF EXISTS branch_variant_prices_price_type_id_index');
        }
    }
};
