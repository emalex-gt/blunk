<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_fel_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_fel_settings', 'establishment_address')) {
                $table->string('establishment_address')->nullable()->after('establishment_name');
            }

            if (! Schema::hasColumn('tenant_fel_settings', 'establishment_postal_code')) {
                $table->string('establishment_postal_code')->nullable()->after('establishment_address');
            }

            if (! Schema::hasColumn('tenant_fel_settings', 'establishment_municipality')) {
                $table->string('establishment_municipality')->nullable()->after('establishment_postal_code');
            }

            if (! Schema::hasColumn('tenant_fel_settings', 'establishment_department')) {
                $table->string('establishment_department')->nullable()->after('establishment_municipality');
            }

            if (! Schema::hasColumn('tenant_fel_settings', 'establishment_country')) {
                $table->string('establishment_country', 2)->default('GT')->after('establishment_department');
            }
        });

        DB::table('tenant_fel_settings')
            ->whereNull('establishment_country')
            ->update(['establishment_country' => 'GT']);
    }

    public function down(): void
    {
        Schema::table('tenant_fel_settings', function (Blueprint $table) {
            foreach ([
                'establishment_country',
                'establishment_department',
                'establishment_municipality',
                'establishment_postal_code',
                'establishment_address',
            ] as $column) {
                if (Schema::hasColumn('tenant_fel_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
