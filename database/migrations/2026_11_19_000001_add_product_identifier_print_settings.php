<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'show_product_code_on_receipts')) {
                $table->boolean('show_product_code_on_receipts')->default(false);
            }

            if (! Schema::hasColumn('tenant_settings', 'show_product_barcode_on_receipts')) {
                $table->boolean('show_product_barcode_on_receipts')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach (['show_product_code_on_receipts', 'show_product_barcode_on_receipts'] as $column) {
                if (Schema::hasColumn('tenant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
