<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_cash_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_cash_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_pre_sale_collection_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('route_delivery_collection_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount_snapshot', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE route_cash_settlement_items ADD CONSTRAINT route_cash_settlement_items_origin_check CHECK (amount_snapshot > 0 AND ((route_pre_sale_collection_id IS NOT NULL AND route_delivery_collection_id IS NULL) OR (route_pre_sale_collection_id IS NULL AND route_delivery_collection_id IS NOT NULL)))");
        DB::statement('CREATE UNIQUE INDEX route_cash_settlement_items_pre_active_unique ON route_cash_settlement_items (route_pre_sale_collection_id) WHERE is_active');
        DB::statement('CREATE UNIQUE INDEX route_cash_settlement_items_delivery_active_unique ON route_cash_settlement_items (route_delivery_collection_id) WHERE is_active');
    }
    public function down(): void { Schema::dropIfExists('route_cash_settlement_items'); }
};
