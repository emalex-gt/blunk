<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'fel_issued_at')) {
                $table->timestamp('fel_issued_at')->nullable()->after('fel_certified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'fel_issued_at')) {
                $table->dropColumn('fel_issued_at');
            }
        });
    }
};
