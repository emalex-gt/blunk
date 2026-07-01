<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'reserve_stock_on_credit_reservations')) {
                $table->boolean('reserve_stock_on_credit_reservations')->default(true)->after('enable_credit_sales');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'reserve_stock_on_credit_reservations')) {
                $table->dropColumn('reserve_stock_on_credit_reservations');
            }
        });
    }
};
