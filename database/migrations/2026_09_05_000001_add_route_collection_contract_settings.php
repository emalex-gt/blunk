<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_settings')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('tenant_settings', 'route_collection_responsibility')) {
                    $table->string('route_collection_responsibility', 32)->default('pre_seller');
                }

                if (! Schema::hasColumn('tenant_settings', 'route_delivery_tracking')) {
                    $table->string('route_delivery_tracking', 32)->default('external');
                }

                if (! Schema::hasColumn('tenant_settings', 'route_cash_custody_policy')) {
                    $table->string('route_cash_custody_policy', 64)->default('collector_custody_until_settlement');
                }
            });
        }

        if (Schema::hasTable('pre_sales')) {
            Schema::table('pre_sales', function (Blueprint $table) {
                if (! Schema::hasColumn('pre_sales', 'agreed_payment_method')) {
                    $table->string('agreed_payment_method')->nullable();
                }
            });

            DB::table('pre_sales')
                ->whereNull('agreed_payment_method')
                ->whereNotNull('payment_method')
                ->update(['agreed_payment_method' => DB::raw('payment_method')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pre_sales') && Schema::hasColumn('pre_sales', 'agreed_payment_method')) {
            Schema::table('pre_sales', fn (Blueprint $table) => $table->dropColumn('agreed_payment_method'));
        }

        if (Schema::hasTable('tenant_settings')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                foreach (['route_collection_responsibility', 'route_delivery_tracking', 'route_cash_custody_policy'] as $column) {
                    if (Schema::hasColumn('tenant_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
