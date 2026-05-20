<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_payments')) {
            Schema::create('sale_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
                $table->string('method');
                $table->decimal('amount', 12, 2);
                $table->string('reference')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'sale_id']);
                $table->index(['business_id', 'method']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
