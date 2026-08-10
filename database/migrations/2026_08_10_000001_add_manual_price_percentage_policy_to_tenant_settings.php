<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'manual_price_percentage_mode')) {
                $table->string('manual_price_percentage_mode')
                    ->default('cost_markup')
                    ->after('manual_price_min_margin_percent');
            }

            if (! Schema::hasColumn('tenant_settings', 'manual_price_min_markup_percent')) {
                $table->decimal('manual_price_min_markup_percent', 6, 2)
                    ->default(0)
                    ->after('manual_price_percentage_mode');
            }

            if (! Schema::hasColumn('tenant_settings', 'manual_price_max_discount_percent')) {
                $table->decimal('manual_price_max_discount_percent', 5, 2)
                    ->default(0)
                    ->after('manual_price_min_markup_percent');
            }
        });

        if (Schema::hasColumn('tenant_settings', 'manual_price_min_margin_percent')
            && Schema::hasColumn('tenant_settings', 'manual_price_min_markup_percent')) {
            DB::table('tenant_settings')
                ->where(function ($query) {
                    $query->whereNull('manual_price_min_markup_percent')
                        ->orWhere('manual_price_min_markup_percent', 0);
                })
                ->update([
                    'manual_price_min_markup_percent' => DB::raw('COALESCE(manual_price_min_margin_percent, 0)'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach ([
                'manual_price_max_discount_percent',
                'manual_price_min_markup_percent',
                'manual_price_percentage_mode',
            ] as $column) {
                if (Schema::hasColumn('tenant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
