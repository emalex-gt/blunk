<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'receipt_format')) {
                $table->string('receipt_format')->default('ticket')->after('company_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'receipt_format')) {
                $table->dropColumn('receipt_format');
            }
        });
    }
};
