<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_fel_phrases', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_fel_phrases', 'data_identifier')) {
                $table->string('data_identifier')->nullable()->after('tenant_fel_setting_id');
            }

            if (! Schema::hasColumn('tenant_fel_phrases', 'phrase_type')) {
                $table->string('phrase_type')->nullable()->after('data_identifier');
            }

            if (! Schema::hasColumn('tenant_fel_phrases', 'scenario_code')) {
                $table->string('scenario_code')->nullable()->after('phrase_type');
            }

            if (! Schema::hasColumn('tenant_fel_phrases', 'resolution_number')) {
                $table->string('resolution_number')->nullable()->after('scenario_code');
            }

            if (! Schema::hasColumn('tenant_fel_phrases', 'resolution_date')) {
                $table->date('resolution_date')->nullable()->after('resolution_number');
            }
        });

        DB::table('tenant_fel_phrases')
            ->whereNull('data_identifier')
            ->update([
                'data_identifier' => DB::raw("COALESCE(type_data, scenario_data, '1')"),
                'phrase_type' => DB::raw("COALESCE(type_value, '1')"),
                'scenario_code' => DB::raw("COALESCE(scenario_value, '1')"),
            ]);
    }

    public function down(): void
    {
        Schema::table('tenant_fel_phrases', function (Blueprint $table) {
            foreach ([
                'resolution_date',
                'resolution_number',
                'scenario_code',
                'phrase_type',
                'data_identifier',
            ] as $column) {
                if (Schema::hasColumn('tenant_fel_phrases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
