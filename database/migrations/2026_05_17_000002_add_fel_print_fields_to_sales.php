<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'fel_uuid')) {
                $table->string('fel_uuid')->nullable()->after('certification_status')->index();
            }

            if (! Schema::hasColumn('sales', 'fel_series')) {
                $table->string('fel_series')->nullable()->after('fel_uuid');
            }

            if (! Schema::hasColumn('sales', 'fel_number')) {
                $table->string('fel_number')->nullable()->after('fel_series');
            }

            if (! Schema::hasColumn('sales', 'fel_xml_path')) {
                $table->string('fel_xml_path')->nullable()->after('fel_number');
            }

            if (! Schema::hasColumn('sales', 'fel_certified_at')) {
                $table->timestamp('fel_certified_at')->nullable()->after('fel_xml_path');
            }

            if (! Schema::hasColumn('sales', 'fel_status')) {
                $table->string('fel_status')->nullable()->default('CERTIFIED')->after('fel_certified_at');
            }

            if (! Schema::hasColumn('sales', 'fel_raw_response')) {
                $table->json('fel_raw_response')->nullable()->after('fel_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            foreach ([
                'fel_raw_response',
                'fel_status',
                'fel_certified_at',
                'fel_xml_path',
                'fel_number',
                'fel_series',
                'fel_uuid',
            ] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
