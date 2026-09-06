<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('route_delivery_collection_id')->nullable()->constrained()->nullOnDelete();
            $table->unique('route_delivery_collection_id', 'sale_payments_route_delivery_collection_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_route_delivery_collection_unique');
            $table->dropConstrainedForeignId('route_delivery_collection_id');
        });
    }
};
