<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'allow_receipts')) {
                $table->boolean('allow_receipts')->default(true);
            }

            if (! Schema::hasColumn('tenant_settings', 'allow_invoices')) {
                $table->boolean('allow_invoices')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'allow_invoices')) {
                $table->dropColumn('allow_invoices');
            }

            if (Schema::hasColumn('tenant_settings', 'allow_receipts')) {
                $table->dropColumn('allow_receipts');
            }
        });
    }
};
