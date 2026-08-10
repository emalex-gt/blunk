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
            if (! Schema::hasColumn('tenant_settings', 'show_other_branches_stock_in_pos')) {
                $table->boolean('show_other_branches_stock_in_pos')->default(false)->after('allow_negative_stock');
            }

            if (! Schema::hasColumn('tenant_settings', 'enable_credit_reservations')) {
                $table->boolean('enable_credit_reservations')->default(false)->after('enable_credit_sales');
            }
        });

        if (Schema::hasColumn('tenant_settings', 'enable_credit_sales')
            && Schema::hasColumn('tenant_settings', 'enable_credit_reservations')) {
            DB::table('tenant_settings')
                ->where('enable_credit_sales', true)
                ->update(['enable_credit_reservations' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach (['enable_credit_reservations', 'show_other_branches_stock_in_pos'] as $column) {
                if (Schema::hasColumn('tenant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
