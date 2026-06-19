<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'allow_duplicate_product_codes')) {
                $table->boolean('allow_duplicate_product_codes')->default(false)->after('allow_negative_stock');
            }

            if (! Schema::hasColumn('tenant_settings', 'allow_duplicate_product_barcodes')) {
                $table->boolean('allow_duplicate_product_barcodes')->default(false)->after('allow_duplicate_product_codes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            foreach (['allow_duplicate_product_barcodes', 'allow_duplicate_product_codes'] as $column) {
                if (Schema::hasColumn('tenant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
