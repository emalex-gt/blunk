<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS customers_business_real_tax_id_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_business_real_tax_id_unique
            ON customers (
                business_id,
                COALESCE(NULLIF(UPPER(BTRIM(doc_type)), ''), 'NIT'),
                normalized_tax_id
            )
            WHERE normalized_tax_id IS NOT NULL AND normalized_tax_id <> 'CF'
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $duplicates = DB::table('customers')
            ->whereNotNull('normalized_tax_id')
            ->where('normalized_tax_id', '!=', 'CF')
            ->select('business_id', 'normalized_tax_id')
            ->groupBy('business_id', 'normalized_tax_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Cannot restore untyped customer identity uniqueness while typed NIT/CUI identities coexist.');
        }

        DB::statement('DROP INDEX IF EXISTS customers_business_real_tax_id_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_business_real_tax_id_unique
            ON customers (business_id, normalized_tax_id)
            WHERE normalized_tax_id IS NOT NULL AND normalized_tax_id <> 'CF'
            SQL);
    }
};
