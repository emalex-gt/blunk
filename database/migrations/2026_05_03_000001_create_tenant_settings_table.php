<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_settings')) {
            Schema::create('tenant_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('use_product_images')->default(true);
                $table->timestamps();
            });

            return;
        }

        Schema::table('tenant_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_settings', 'use_product_images')) {
                $table->boolean('use_product_images')->default(true);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_settings') && Schema::hasColumn('tenant_settings', 'use_product_images')) {
            Schema::table('tenant_settings', function (Blueprint $table) {
                $table->dropColumn('use_product_images');
            });
        }
    }
};
