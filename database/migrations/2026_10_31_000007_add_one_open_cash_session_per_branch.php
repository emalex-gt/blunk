<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'cash_register_sessions_one_open_per_branch';

    public function up(): void
    {
        if (
            DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('cash_register_sessions')
            || ! Schema::hasColumn('cash_register_sessions', 'branch_id')
        ) {
            return;
        }

        if (DB::table('cash_register_sessions')
            ->where('status', 'open')
            ->whereNull('branch_id')
            ->exists()) {
            throw new RuntimeException(
                'No se puede garantizar una única caja abierta por sucursal: existen sesiones abiertas sin sucursal. Corrige los datos manualmente antes de reintentar.',
            );
        }

        if (DB::table('cash_register_sessions')
            ->select('business_id', 'branch_id')
            ->where('status', 'open')
            ->groupBy('business_id', 'branch_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException(
                'No se puede garantizar una única caja abierta por sucursal: existen sesiones abiertas duplicadas. Corrige los datos manualmente antes de reintentar.',
            );
        }

        DB::statement(sprintf(
            "CREATE UNIQUE INDEX IF NOT EXISTS %s ON cash_register_sessions (business_id, branch_id) WHERE status = 'open'",
            self::INDEX,
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));
    }
};
