<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->text('address_reference')->nullable();
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->text('customer_address_reference')->nullable();
        });

        Schema::table('route_delivery_stops', function (Blueprint $table): void {
            $table->text('customer_address_reference_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('route_delivery_stops', function (Blueprint $table): void {
            $table->dropColumn('customer_address_reference_snapshot');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn('customer_address_reference');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('address_reference');
        });
    }
};
