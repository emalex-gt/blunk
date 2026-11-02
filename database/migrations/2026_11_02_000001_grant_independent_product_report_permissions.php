<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $newPermissions = [
            ['key' => 'reports.products_without_cost.view', 'name' => 'Ver productos sin costo', 'description' => 'Permite revisar productos sin costo configurado.'],
            ['key' => 'reports.anomalous_prices.view', 'name' => 'Ver precios anómalos', 'description' => 'Permite revisar precios de venta anómalos respecto del costo.'],
            ['key' => 'reports.products_without_sales.view', 'name' => 'Ver productos sin ventas', 'description' => 'Permite identificar productos con existencias y sin ventas en el período seleccionado.'],
        ];

        foreach ($newPermissions as $permission) {
            $payload = [
                ...$permission,
                'group' => 'Reportes',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('permissions', 'is_system')) {
                $payload['is_system'] = true;
            }

            DB::table('permissions')->insertOrIgnore($payload);
        }

        $permissions = DB::table('permissions')
            ->whereIn('key', [
                'reports.profit.view',
                'reports.inventory.view',
                'reports.products_without_cost.view',
                'reports.anomalous_prices.view',
                'reports.products_without_sales.view',
            ])
            ->pluck('id', 'key');

        $transitions = [
            'reports.profit.view' => [
                'reports.products_without_cost.view',
                'reports.anomalous_prices.view',
            ],
            'reports.inventory.view' => ['reports.products_without_sales.view'],
        ];

        foreach ($transitions as $sourceKey => $targetKeys) {
            $sourceId = $permissions[$sourceKey] ?? null;

            foreach ($targetKeys as $targetKey) {
                $targetId = $permissions[$targetKey] ?? null;

                if (! $sourceId || ! $targetId) {
                    continue;
                }

                if (Schema::hasTable('role_has_permissions')) {
                    DB::table('role_has_permissions')
                        ->select('role_id')
                        ->where('permission_id', $sourceId)
                        ->orderBy('role_id')
                        ->each(fn ($role) => DB::table('role_has_permissions')->insertOrIgnore([
                            'role_id' => $role->role_id,
                            'permission_id' => $targetId,
                        ]));
                }

                if (Schema::hasTable('model_has_permissions')) {
                    DB::table('model_has_permissions')
                        ->select(['model_type', 'model_id'])
                        ->where('permission_id', $sourceId)
                        ->orderBy('model_id')
                        ->each(fn ($assignment) => DB::table('model_has_permissions')->insertOrIgnore([
                            'permission_id' => $targetId,
                            'model_type' => $assignment->model_type,
                            'model_id' => $assignment->model_id,
                        ]));
                }
            }
        }
    }

    public function down(): void
    {
        // The copied permissions are intentionally retained: a later revocation is an administrative decision.
    }
};
