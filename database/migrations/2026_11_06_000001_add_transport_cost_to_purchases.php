<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('transport_cost', 15, 2)->default(0)->after('total');
        });
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_transport_cost_nonnegative CHECK (transport_cost >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchases DROP CONSTRAINT purchases_transport_cost_nonnegative');
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('transport_cost');
        });
    }
};
