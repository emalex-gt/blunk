<?php

use App\Support\CustomerIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'normalized_tax_id')) {
                $table->string('normalized_tax_id')->nullable()->after('doc_number');
            }

            if (! Schema::hasColumn('customers', 'merged_into_customer_id')) {
                $table->foreignId('merged_into_customer_id')->nullable()->after('business_id')->constrained('customers')->nullOnDelete();
            }

            if (! Schema::hasColumn('customers', 'merged_at')) {
                $table->timestamp('merged_at')->nullable()->after('merged_into_customer_id');
            }
        });

        DB::table('customers')->orderBy('id')->each(function (object $customer): void {
            DB::table('customers')->where('id', $customer->id)->update([
                'normalized_tax_id' => CustomerIdentity::normalizeTaxId($customer->doc_number),
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['business_id', 'normalized_tax_id'], 'customers_business_normalized_tax_id_index');
        });

        if (DB::getDriverName() === 'pgsql'
            && ! DB::table('pg_indexes')
                ->where('schemaname', DB::raw('current_schema()'))
                ->where('indexname', 'customers_business_real_tax_id_unique')
                ->exists()
        ) {
            $duplicateGroups = DB::table('customers')
                ->whereNotNull('normalized_tax_id')
                ->where('normalized_tax_id', '!=', 'CF')
                ->select('business_id', 'normalized_tax_id')
                ->selectRaw('COUNT(*) as customer_count')
                ->groupBy('business_id', 'normalized_tax_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($duplicateGroups->isEmpty()) {
                DB::statement('CREATE UNIQUE INDEX customers_business_real_tax_id_unique ON customers (business_id, normalized_tax_id) WHERE normalized_tax_id IS NOT NULL AND normalized_tax_id <> \'CF\'');
            } else {
                Log::warning('Customer real tax ID unique index was not created because historical duplicates exist.', [
                    'duplicate_groups' => $duplicateGroups->count(),
                    'duplicate_customers' => $duplicateGroups->sum('customer_count'),
                    'recommended_command' => 'php artisan customers:audit-final-consumer --business=ID --dry-run --report',
                ]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS customers_business_real_tax_id_unique');
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_business_normalized_tax_id_index');
            $table->dropConstrainedForeignId('merged_into_customer_id');
            $table->dropColumn(['merged_at', 'normalized_tax_id']);
        });
    }
};
