<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'tax_lookup_payload')) {
                $table->jsonb('tax_lookup_payload')->nullable()->after('name_locked');
            }

            if (! Schema::hasColumn('customers', 'tax_lookup_verified_at')) {
                $table->timestamp('tax_lookup_verified_at')->nullable()->after('tax_lookup_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'tax_lookup_verified_at')) {
                $table->dropColumn('tax_lookup_verified_at');
            }
        });
    }
};
