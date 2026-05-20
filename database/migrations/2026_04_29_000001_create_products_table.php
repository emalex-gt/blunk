<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->decimal('sale_price', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(0);
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'code']);
            $table->index(['business_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
