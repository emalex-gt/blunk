<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fel_incidents', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('fel_incidents')->whereNull('sale_id')->exists()) {
            throw new RuntimeException('No se puede restaurar sale_id como obligatorio mientras existan incidencias FEL sin venta local.');
        }

        Schema::table('fel_incidents', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};
