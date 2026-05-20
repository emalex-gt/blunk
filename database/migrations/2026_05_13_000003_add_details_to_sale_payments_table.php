<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_payments', 'details')) {
                $table->jsonb('details')->nullable()->after('reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            if (Schema::hasColumn('sale_payments', 'details')) {
                $table->dropColumn('details');
            }
        });
    }
};
