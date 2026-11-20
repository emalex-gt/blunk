<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'inventory.transfers.cancel'],
            [
                'name' => 'Anular traslados',
                'group' => 'Sucursales',
                'is_system' => true,
            ],
        );

        Role::query()
            ->whereNull('business_id')
            ->whereIn('key', ['owner', 'admin', 'stock_manager'])
            ->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        // Preserve grants during rollback: they may already be administered explicitly.
    }
};
