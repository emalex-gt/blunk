<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('commercial_name');
            }
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'pre_sale_price_type_id')) {
                $table->foreignId('pre_sale_price_type_id')
                    ->nullable()
                    ->after('remember_last_customer_product_price')
                    ->constrained('price_types')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('tenant_settings', 'pre_sale_allow_manual_price')) {
                $table->boolean('pre_sale_allow_manual_price')
                    ->default(false)
                    ->after('pre_sale_price_type_id');
            }
        });

        Schema::table('pre_sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_sale_items', 'original_price')) {
                $table->decimal('original_price', 12, 2)->nullable()->after('unit_price');
            }

            if (! Schema::hasColumn('pre_sale_items', 'manual_price')) {
                $table->boolean('manual_price')->default(false)->after('original_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pre_sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('pre_sale_items', 'manual_price')) {
                $table->dropColumn('manual_price');
            }

            if (Schema::hasColumn('pre_sale_items', 'original_price')) {
                $table->dropColumn('original_price');
            }
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_settings', 'pre_sale_allow_manual_price')) {
                $table->dropColumn('pre_sale_allow_manual_price');
            }

            if (Schema::hasColumn('tenant_settings', 'pre_sale_price_type_id')) {
                $table->dropConstrainedForeignId('pre_sale_price_type_id');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'contact_name')) {
                $table->dropColumn('contact_name');
            }
        });
    }
};
