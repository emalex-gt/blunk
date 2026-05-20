<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'is_final_consumer')) {
                $table->boolean('is_final_consumer')->default(false)->after('country');
            }

            if (! Schema::hasColumn('customers', 'name_locked')) {
                $table->boolean('name_locked')->default(false)->after('is_final_consumer');
            }

            if (! Schema::hasColumn('customers', 'tax_lookup_payload')) {
                $table->jsonb('tax_lookup_payload')->nullable()->after('name_locked');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['tax_lookup_payload', 'name_locked', 'is_final_consumer'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
