<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['business_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('business_id')
                ->constrained('categories')
                ->nullOnDelete();

            $table->index(['business_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropIndex(['business_id', 'category_id']);
            $table->dropColumn('category_id');
        });

        Schema::dropIfExists('categories');
    }
};
