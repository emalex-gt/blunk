<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('status', 16)->default('captured')->after('route_delivery_collection_id');
        });
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_status_check CHECK (status IN ('captured', 'reversed'))");
    }

    public function down(): void
    {
        if (DB::table('sale_payments')->where('status', 'reversed')->exists()
            || (Schema::hasTable('route_delivery_collection_reversals') && DB::table('route_delivery_collection_reversals')->exists())) {
            throw new RuntimeException('Cannot roll back 3E-B1 payment status while reversed payments or reversal ledger evidence exists.');
        }
        DB::statement('ALTER TABLE sale_payments DROP CONSTRAINT sale_payments_status_check');
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
