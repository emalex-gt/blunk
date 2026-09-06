<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_delivery_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('route_delivery_batches', 'delivery_tracking_snapshot')) {
                $table->string('delivery_tracking_snapshot', 32)->nullable();
            }

            if (! Schema::hasColumn('route_delivery_batches', 'collection_responsibility_snapshot')) {
                $table->string('collection_responsibility_snapshot', 32)->nullable();
            }

            if (! Schema::hasColumn('route_delivery_batches', 'operation_settings_snapshotted_at')) {
                $table->timestamp('operation_settings_snapshotted_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('route_delivery_batches', function (Blueprint $table) {
            foreach (['delivery_tracking_snapshot', 'collection_responsibility_snapshot', 'operation_settings_snapshotted_at'] as $column) {
                if (Schema::hasColumn('route_delivery_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
