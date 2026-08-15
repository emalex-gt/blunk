<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('route_work_days')) {
            Schema::table('route_work_days', function (Blueprint $table) {
                if (! Schema::hasColumn('route_work_days', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('closed_at');
                }

                if (! Schema::hasColumn('route_work_days', 'completed_by')) {
                    $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('tenant_settings')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('tenant_settings', 'route_pre_sale_invoicing_mode')) {
                    $table->string('route_pre_sale_invoicing_mode', 20)->default('manual')->after('pre_sale_allow_manual_price');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('route_work_days')) {
            Schema::table('route_work_days', function (Blueprint $table) {
                if (Schema::hasColumn('route_work_days', 'completed_by')) {
                    $table->dropConstrainedForeignId('completed_by');
                }

                if (Schema::hasColumn('route_work_days', 'completed_at')) {
                    $table->dropColumn('completed_at');
                }
            });
        }

        if (Schema::hasTable('tenant_settings')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                if (Schema::hasColumn('tenant_settings', 'route_pre_sale_invoicing_mode')) {
                    $table->dropColumn('route_pre_sale_invoicing_mode');
                }
            });
        }
    }
};
