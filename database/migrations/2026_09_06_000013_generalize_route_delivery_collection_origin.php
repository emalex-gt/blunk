<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_delivery_collections', function (Blueprint $table) {
            $table->foreignId('route_external_delivery_reconciliation_item_id')->nullable()->change();
            $table->foreignId('route_delivery_stop_id')->nullable()->constrained()->nullOnDelete()->unique('route_delivery_collections_stop_unique');
            $table->string('delivery_origin', 32)->nullable()->after('route_delivery_stop_id');
        });
        DB::table('route_delivery_collections')->whereNotNull('route_external_delivery_reconciliation_item_id')->update(['delivery_origin' => 'external_reconciliation']);
        DB::statement('ALTER TABLE route_delivery_collections ALTER COLUMN delivery_origin SET NOT NULL');
        DB::statement("ALTER TABLE route_delivery_collections ADD CONSTRAINT route_delivery_collections_origin_check CHECK ((delivery_origin = 'external_reconciliation' AND route_external_delivery_reconciliation_item_id IS NOT NULL AND route_delivery_stop_id IS NULL) OR (delivery_origin = 'in_app_stop' AND route_external_delivery_reconciliation_item_id IS NULL AND route_delivery_stop_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::table('route_delivery_collections', function (Blueprint $table) {
            $table->dropUnique('route_delivery_collections_stop_unique');
            $table->dropConstrainedForeignId('route_delivery_stop_id');
            $table->dropColumn('delivery_origin');
            $table->foreignId('route_external_delivery_reconciliation_item_id')->nullable(false)->change();
        });
    }
};
