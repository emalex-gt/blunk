<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            throw new \RuntimeException('La tabla permissions de Blunk todavía no existe.');
        }
        $now = now();
        foreach ([
            'fuel.view' => 'Ver consumos de Blunk Fuel',
            'fuel.record_manual' => 'Registrar consumos manuales en Blunk Fuel',
            'fuel.record_automatic' => 'Registrar consumos automáticos de surtidores',
        ] as $key => $name) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'group' => 'Fuel', 'description' => 'Permiso de API Blunk Fuel', 'is_system' => true, 'updated_at' => $now],
            );
        }
        // No automatic grants. An administrator must explicitly assign access.
    }

    public function down(): void
    {
        // Do not remove permissions that might now be assigned to users/roles.
    }
};
