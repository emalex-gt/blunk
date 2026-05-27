<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_settings')
            || ! Schema::hasColumn('tenant_settings', 'allow_invoices')
            || ! Schema::hasTable('tenant_fel_settings')
            || ! Schema::hasTable('tenant_modules')
        ) {
            return;
        }

        $enabledFelBusinesses = DB::table('tenant_fel_settings as fel')
            ->join('businesses as business', 'business.id', '=', 'fel.business_id')
            ->join('tenant_modules as module', function ($join) {
                $join->on('module.business_id', '=', 'fel.business_id')
                    ->where('module.module', '=', 'fel_gt')
                    ->where('module.is_enabled', '=', true);
            })
            ->where('business.country', 'GT')
            ->where('fel.enabled', true)
            ->select('fel.business_id');

        DB::table('tenant_settings')
            ->whereIn('business_id', $enabledFelBusinesses)
            ->update(['allow_invoices' => true]);
    }

    public function down(): void
    {
        // Do not disable invoices that may have been intentionally configured after this backfill.
    }
};
