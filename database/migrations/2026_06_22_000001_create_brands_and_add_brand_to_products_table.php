<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
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

        if (! Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('brands')
                    ->nullOnDelete();

                $table->index(['business_id', 'brand_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['brand_id']);
                $table->dropIndex(['business_id', 'brand_id']);
                $table->dropColumn('brand_id');
            });
        }

        Schema::dropIfExists('brands');
    }
};
