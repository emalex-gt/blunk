<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach ([
                'company_logo_url',
                'company_logo_public_id',
                'company_name',
                'company_tax_id',
                'company_address',
                'company_phone',
            ] as $column) {
                if (! Schema::hasColumn('tenant_settings', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'document_type')) {
                $table->string('document_type')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'document_type')) {
                $table->dropColumn('document_type');
            }
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach ([
                'company_logo_url',
                'company_logo_public_id',
                'company_name',
                'company_tax_id',
                'company_address',
                'company_phone',
            ] as $column) {
                if (Schema::hasColumn('tenant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
