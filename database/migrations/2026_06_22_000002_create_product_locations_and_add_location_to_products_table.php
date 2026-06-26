<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_locations')) {
            Schema::create('product_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'name']);
                $table->index(['business_id', 'is_active']);
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'location_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('location_id')
                    ->nullable()
                    ->after('brand_id')
                    ->constrained('product_locations')
                    ->nullOnDelete();

                $table->index(['business_id', 'location_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'location_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['location_id']);
                $table->dropIndex(['business_id', 'location_id']);
                $table->dropColumn('location_id');
            });
        }

        Schema::dropIfExists('product_locations');
    }
};
