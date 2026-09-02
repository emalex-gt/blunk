<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_settings') && ! Schema::hasColumn('tenant_settings', 'route_pre_sale_require_fel_eligible_customer')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->boolean('route_pre_sale_require_fel_eligible_customer')
                    ->default(false)
                    ->after('route_pre_sale_stock_deduction_timing');
            });
        }

        if (Schema::hasTable('pre_sales') && ! Schema::hasColumn('pre_sales', 'fel_eligibility_status')) {
            Schema::table('pre_sales', function (Blueprint $table) {
                $table->string('fel_eligibility_status', 20)->nullable()->after('converted_sale_id');
                $table->string('fel_eligibility_reason_code', 80)->nullable()->after('fel_eligibility_status');
                $table->text('fel_eligibility_reason')->nullable()->after('fel_eligibility_reason_code');
                $table->timestamp('fel_eligibility_checked_at')->nullable()->after('fel_eligibility_reason');
                $table->index(['business_id', 'fel_eligibility_status'], 'pre_sales_business_fel_eligibility_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pre_sales') && Schema::hasColumn('pre_sales', 'fel_eligibility_status')) {
            Schema::table('pre_sales', function (Blueprint $table) {
                $table->dropIndex('pre_sales_business_fel_eligibility_idx');
                $table->dropColumn([
                    'fel_eligibility_status',
                    'fel_eligibility_reason_code',
                    'fel_eligibility_reason',
                    'fel_eligibility_checked_at',
                ]);
            });
        }

        if (Schema::hasTable('tenant_settings') && Schema::hasColumn('tenant_settings', 'route_pre_sale_require_fel_eligible_customer')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->dropColumn('route_pre_sale_require_fel_eligible_customer');
            });
        }
    }
};
