<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'reports.sales.summary.view'],
            [
                'name' => 'Ver resumen y totales de ventas',
                'group' => 'Reportes',
                'is_system' => true,
            ],
        );

        Role::query()
            ->whereNull('business_id')
            ->whereIn('key', ['owner', 'admin', 'reports'])
            ->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        // Deliberately preserve administrator assignments during rollback.
    }
};
