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
            $table->string('status', 16)->default('captured')->after('delivery_origin');
        });

        DB::statement("ALTER TABLE route_delivery_collections ADD CONSTRAINT route_delivery_collections_status_check CHECK (status IN ('captured', 'reversed'))");
        Schema::table('route_delivery_collections', function (Blueprint $table) {
            $table->dropUnique('route_delivery_collections_sale_unique');
            $table->dropUnique('route_delivery_collections_item_unique');
        });
        DB::statement("CREATE UNIQUE INDEX route_delivery_collections_sale_captured_unique ON route_delivery_collections (sale_id) WHERE status = 'captured'");
        DB::statement("CREATE UNIQUE INDEX route_delivery_collections_external_captured_unique ON route_delivery_collections (route_external_delivery_reconciliation_item_id) WHERE status = 'captured' AND route_external_delivery_reconciliation_item_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX route_delivery_collections_stop_captured_unique ON route_delivery_collections (route_delivery_stop_id) WHERE status = 'captured' AND route_delivery_stop_id IS NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('route_delivery_collections')->where('status', 'reversed')->exists()
            || DB::table('route_delivery_collections')->select('sale_id')->groupBy('sale_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('route_delivery_collections')->whereNotNull('route_external_delivery_reconciliation_item_id')->select('route_external_delivery_reconciliation_item_id')->groupBy('route_external_delivery_reconciliation_item_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back 3E-B1 collection status: reversal history cannot be represented by the prior global unique constraints.');
        }

        DB::statement('DROP INDEX route_delivery_collections_sale_captured_unique');
        DB::statement('DROP INDEX route_delivery_collections_external_captured_unique');
        DB::statement('DROP INDEX route_delivery_collections_stop_captured_unique');
        DB::statement('ALTER TABLE route_delivery_collections DROP CONSTRAINT route_delivery_collections_status_check');
        Schema::table('route_delivery_collections', function (Blueprint $table) {
            $table->unique('sale_id', 'route_delivery_collections_sale_unique');
            $table->unique('route_external_delivery_reconciliation_item_id', 'route_delivery_collections_item_unique');
            $table->dropColumn('status');
        });
    }
};
