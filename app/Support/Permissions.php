<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class Permissions
{
    public const POS_VIEW = 'pos.view';
    public const POS_SELL = 'pos.sell';
    public const POS_APPLY_DISCOUNT = 'pos.apply_discount';
    public const POS_MANUAL_PRICE = 'pos.manual_price';

    public const SALES_VIEW = 'sales.view';
    public const SALES_CANCEL = 'sales.cancel';
    public const SALES_PRINT = 'sales.print';
    public const SALES_DISCOUNT_APPLY = 'sales.discount.apply';

    public const PURCHASES_VIEW = 'purchases.view';
    public const PURCHASES_CREATE = 'purchases.create';
    public const PURCHASES_CANCEL = 'purchases.cancel';

    public const PRODUCTS_VIEW = 'products.view';
    public const PRODUCTS_CREATE = 'products.create';
    public const PRODUCTS_UPDATE = 'products.update';
    public const PRODUCTS_DELETE = 'products.delete';

    public const INVENTORY_VIEW = 'inventory.view';
    public const INVENTORY_ADJUST = 'inventory.adjust';
    public const INVENTORY_TRANSFER = 'inventory.transfer';

    public const CASH_REGISTER_VIEW = 'cash_register.view';
    public const CASH_REGISTER_OPEN = 'cash_register.open';
    public const CASH_REGISTER_CLOSE = 'cash_register.close';
    public const CASH_REGISTER_EXPENSES = 'cash_register.expenses';

    public const REPORTS_SALES_VIEW = 'reports.sales.view';
    public const REPORTS_STOCK_VIEW = 'reports.stock.view';
    public const REPORTS_TOP_PRODUCTS_VIEW = 'reports.top_products.view';

    public const CUSTOMERS_VIEW = 'customers.view';
    public const CUSTOMERS_CREATE = 'customers.create';
    public const CUSTOMERS_UPDATE = 'customers.update';

    public const FEL_DOCUMENTS_VIEW = 'fel.documents.view';
    public const FEL_CERTIFY = 'fel.certify';
    public const FEL_CANCEL = 'fel.cancel';
    public const FEL_TECHNICAL_VIEW = 'fel.technical.view';

    public const PRICE_LISTS_VIEW = 'price_lists.view';
    public const PRICE_LISTS_MANAGE = 'price_lists.manage';

    public const CREDITS_VIEW = 'credits.view';
    public const CREDITS_CREATE = 'credits.create';
    public const CREDITS_INVOICE = 'credits.invoice';
    public const CREDITS_TRANSFER_CUSTOMER = 'credits.transfer_customer';
    public const CREDITS_CANCEL_LINES = 'credits.cancel_lines';
    public const CREDITS_PRINT = 'credits.print';
    public const CREDITS_MANAGE = 'credits.manage';

    public const USERS_VIEW = 'users.view';
    public const USERS_CREATE = 'users.create';
    public const USERS_UPDATE = 'users.update';
    public const USERS_ASSIGN_ROLES = 'users.assign_roles';
    public const USERS_DELETE = 'users.delete';

    public const BRANCHES_VIEW = 'branches.view';
    public const BRANCHES_MANAGE = 'branches.manage';
    public const BRANCHES_SWITCH = 'branches.switch';
    public const INVENTORY_TRANSFERS_VIEW = 'inventory.transfers.view';
    public const INVENTORY_TRANSFERS_CREATE = 'inventory.transfers.create';

    public const TENANT_SETTINGS_VIEW = 'tenant.settings.view';
    public const TENANT_SETTINGS_MANAGE = 'tenant.settings.manage';

    public const SUPER_ADMIN_ACCESS = 'super_admin.access';
    public const SUPER_ADMIN_TENANTS_MANAGE = 'super_admin.tenants.manage';
    public const SUPER_ADMIN_ROLES_MANAGE = 'super_admin.roles.manage';
    public const SUPER_ADMIN_PERMISSIONS_MANAGE = 'super_admin.permissions.manage';

    public static function catalog(): array
    {
        return [
            self::POS_VIEW => ['name' => 'Ver POS', 'group' => 'POS'],
            self::POS_SELL => ['name' => 'Vender en POS', 'group' => 'POS'],
            self::POS_APPLY_DISCOUNT => ['name' => 'Aplicar descuentos POS', 'group' => 'POS'],
            self::POS_MANUAL_PRICE => ['name' => 'Aplicar precio manual', 'group' => 'POS'],
            self::SALES_VIEW => ['name' => 'Ver ventas', 'group' => 'Ventas'],
            self::SALES_CANCEL => ['name' => 'Anular ventas', 'group' => 'Ventas'],
            self::SALES_PRINT => ['name' => 'Imprimir ventas', 'group' => 'Ventas'],
            self::SALES_DISCOUNT_APPLY => ['name' => 'Aplicar descuento general', 'group' => 'Ventas'],
            self::PURCHASES_VIEW => ['name' => 'Ver compras', 'group' => 'Compras'],
            self::PURCHASES_CREATE => ['name' => 'Crear compras', 'group' => 'Compras'],
            self::PURCHASES_CANCEL => ['name' => 'Anular compras', 'group' => 'Compras'],
            self::PRODUCTS_VIEW => ['name' => 'Ver productos', 'group' => 'Inventario'],
            self::PRODUCTS_CREATE => ['name' => 'Crear productos', 'group' => 'Inventario'],
            self::PRODUCTS_UPDATE => ['name' => 'Editar productos', 'group' => 'Inventario'],
            self::PRODUCTS_DELETE => ['name' => 'Eliminar productos', 'group' => 'Inventario'],
            self::INVENTORY_VIEW => ['name' => 'Ver inventario', 'group' => 'Inventario'],
            self::INVENTORY_ADJUST => ['name' => 'Ajustar inventario', 'group' => 'Inventario'],
            self::INVENTORY_TRANSFER => ['name' => 'Trasladar inventario', 'group' => 'Inventario'],
            self::CASH_REGISTER_VIEW => ['name' => 'Ver caja', 'group' => 'Caja'],
            self::CASH_REGISTER_OPEN => ['name' => 'Abrir caja', 'group' => 'Caja'],
            self::CASH_REGISTER_CLOSE => ['name' => 'Cerrar caja', 'group' => 'Caja'],
            self::CASH_REGISTER_EXPENSES => ['name' => 'Registrar gastos de caja', 'group' => 'Caja'],
            self::REPORTS_SALES_VIEW => ['name' => 'Ver reporte de ventas', 'group' => 'Reportes'],
            self::REPORTS_STOCK_VIEW => ['name' => 'Ver reporte de stock', 'group' => 'Reportes'],
            self::REPORTS_TOP_PRODUCTS_VIEW => ['name' => 'Ver productos más vendidos', 'group' => 'Reportes'],
            self::CUSTOMERS_VIEW => ['name' => 'Ver clientes', 'group' => 'Clientes'],
            self::CUSTOMERS_CREATE => ['name' => 'Crear clientes', 'group' => 'Clientes'],
            self::CUSTOMERS_UPDATE => ['name' => 'Editar clientes', 'group' => 'Clientes'],
            self::FEL_DOCUMENTS_VIEW => ['name' => 'Ver documentos FEL', 'group' => 'FEL'],
            self::FEL_CERTIFY => ['name' => 'Certificar FEL', 'group' => 'FEL'],
            self::FEL_CANCEL => ['name' => 'Anular FEL', 'group' => 'FEL'],
            self::FEL_TECHNICAL_VIEW => ['name' => 'Ver respuesta técnica FEL', 'group' => 'FEL'],
            self::PRICE_LISTS_VIEW => ['name' => 'Ver listas de precios', 'group' => 'Precios'],
            self::PRICE_LISTS_MANAGE => ['name' => 'Gestionar listas de precios', 'group' => 'Precios'],
            self::CREDITS_VIEW => ['name' => 'Ver créditos', 'group' => 'Créditos'],
            self::CREDITS_CREATE => ['name' => 'Crear créditos', 'group' => 'Créditos'],
            self::CREDITS_INVOICE => ['name' => 'Facturar créditos', 'group' => 'Créditos'],
            self::CREDITS_TRANSFER_CUSTOMER => ['name' => 'Transferir deuda de créditos', 'group' => 'Créditos'],
            self::CREDITS_CANCEL_LINES => ['name' => 'Cancelar líneas de crédito', 'group' => 'Créditos'],
            self::CREDITS_PRINT => ['name' => 'Imprimir créditos', 'group' => 'Créditos'],
            self::CREDITS_MANAGE => ['name' => 'Gestionar créditos', 'group' => 'Créditos'],
            self::USERS_VIEW => ['name' => 'Ver usuarios', 'group' => 'Usuarios'],
            self::USERS_CREATE => ['name' => 'Crear usuarios', 'group' => 'Usuarios'],
            self::USERS_UPDATE => ['name' => 'Editar usuarios', 'group' => 'Usuarios'],
            self::USERS_ASSIGN_ROLES => ['name' => 'Asignar roles', 'group' => 'Usuarios'],
            self::USERS_DELETE => ['name' => 'Eliminar usuarios', 'group' => 'Usuarios'],
            self::BRANCHES_VIEW => ['name' => 'Ver sucursales', 'group' => 'Sucursales'],
            self::BRANCHES_MANAGE => ['name' => 'Gestionar sucursales', 'group' => 'Sucursales'],
            self::BRANCHES_SWITCH => ['name' => 'Cambiar sucursal activa', 'group' => 'Sucursales'],
            self::INVENTORY_TRANSFERS_VIEW => ['name' => 'Ver traslados', 'group' => 'Sucursales'],
            self::INVENTORY_TRANSFERS_CREATE => ['name' => 'Crear traslados', 'group' => 'Sucursales'],
            self::TENANT_SETTINGS_VIEW => ['name' => 'Ver configuración tenant', 'group' => 'Configuración'],
            self::TENANT_SETTINGS_MANAGE => ['name' => 'Gestionar configuración tenant', 'group' => 'Configuración'],
            self::SUPER_ADMIN_ACCESS => ['name' => 'Acceso Super Admin', 'group' => 'Super Admin'],
            self::SUPER_ADMIN_TENANTS_MANAGE => ['name' => 'Gestionar tenants', 'group' => 'Super Admin'],
            self::SUPER_ADMIN_ROLES_MANAGE => ['name' => 'Gestionar roles', 'group' => 'Super Admin'],
            self::SUPER_ADMIN_PERMISSIONS_MANAGE => ['name' => 'Gestionar permisos', 'group' => 'Super Admin'],
        ];
    }

    public static function defaultRolePermissions(): array
    {
        $tenantPermissions = array_values(array_filter(
            array_keys(self::catalog()),
            fn (string $permission) => ! str_starts_with($permission, 'super_admin.')
        ));

        return [
            'super_admin' => array_keys(self::catalog()),
            'owner' => $tenantPermissions,
            'admin' => array_values(array_diff($tenantPermissions, [
                self::TENANT_SETTINGS_MANAGE,
                self::BRANCHES_MANAGE,
            ])),
            'cashier' => [
                self::POS_VIEW,
                self::POS_SELL,
                self::SALES_VIEW,
                self::SALES_PRINT,
                self::CUSTOMERS_VIEW,
                self::CUSTOMERS_CREATE,
                self::CREDITS_CREATE,
                self::CREDITS_PRINT,
            ],
            'stock_manager' => [
                self::PRODUCTS_VIEW,
                self::PRODUCTS_CREATE,
                self::PRODUCTS_UPDATE,
                self::INVENTORY_VIEW,
                self::INVENTORY_ADJUST,
                self::INVENTORY_TRANSFER,
                self::INVENTORY_TRANSFERS_VIEW,
                self::INVENTORY_TRANSFERS_CREATE,
            ],
            'purchases' => [
                self::PURCHASES_VIEW,
                self::PURCHASES_CREATE,
                self::PRODUCTS_VIEW,
                self::INVENTORY_VIEW,
            ],
            'reports' => [
                self::REPORTS_SALES_VIEW,
                self::REPORTS_STOCK_VIEW,
                self::REPORTS_TOP_PRODUCTS_VIEW,
                self::CREDITS_VIEW,
            ],
        ];
    }

    public static function roleLabels(): array
    {
        return [
            'super_admin' => 'Super Admin',
            'owner' => 'Owner',
            'admin' => 'Admin',
            'cashier' => 'Cajero',
            'stock_manager' => 'Inventario',
            'purchases' => 'Compras',
            'reports' => 'Reportes',
        ];
    }

    public static function syncDefaults(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $permissionIds = [];
        foreach (self::catalog() as $key => $meta) {
            $permissionPayload = [
                'name' => $meta['name'],
                'group' => $meta['group'] ?? null,
                'description' => $meta['description'] ?? null,
            ];

            if (Schema::hasColumn('permissions', 'is_system')) {
                $permissionPayload['is_system'] = true;
            }

            $permission = Permission::query()->updateOrCreate(
                ['key' => $key],
                $permissionPayload,
            );
            $permissionIds[$key] = $permission->id;
        }

        foreach (self::defaultRolePermissions() as $key => $permissions) {
            $rolePayload = [
                'name' => self::roleLabels()[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'is_system' => true,
            ];

            if (Schema::hasColumn('roles', 'is_active')) {
                $rolePayload['is_active'] = true;
            }

            $role = Role::query()->updateOrCreate(
                ['business_id' => null, 'key' => $key],
                $rolePayload,
            );

            $role->permissions()->sync(array_values(array_filter(
                array_map(fn (string $permission) => $permissionIds[$permission] ?? null, $permissions)
            )));
        }

        self::backfillUserRoles();
    }

    public static function backfillUserRoles(): void
    {
        if (! Schema::hasTable('model_has_roles')) {
            return;
        }

        $roles = Role::query()->whereNull('business_id')->pluck('id', 'key');

        User::query()
            ->select(['id', 'role', 'is_super_admin'])
            ->chunkById(200, function ($users) use ($roles) {
                foreach ($users as $user) {
                    $key = $user->is_super_admin ? 'super_admin' : ($user->role ?: 'cashier');
                    $roleId = $roles[$key] ?? null;

                    if (! $roleId) {
                        continue;
                    }

                    $user->roles()->syncWithoutDetaching([$roleId]);
                }
            });
    }

    public static function assignRole(User $user, string $roleKey): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $role = Role::query()
            ->where('key', $roleKey)
            ->where(function ($query) use ($user) {
                $query->whereNull('business_id');

                if ($user->business_id) {
                    $query->orWhere('business_id', $user->business_id);
                }
            })
            ->when(Schema::hasColumn('roles', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderByRaw('CASE WHEN business_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        if (! $role) {
            return;
        }

        $user->roles()->sync([$role->id]);
        $user->forceFill(['role' => $roleKey])->save();
    }

    public static function assignDirectPermissions(User $user, array $permissionKeys): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('model_has_permissions')) {
            return;
        }

        $ids = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->all();

        $user->directPermissions()->sync($ids);
    }

    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->is_super_admin) {
            return array_keys(self::catalog());
        }

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return [];
        }

        $rolePermissions = $user->roles()
            ->with('permissions:key')
            ->when(Schema::hasColumn('roles', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('key'));

        $directPermissions = $user->directPermissions()
            ->pluck('key');

        $permissions = $rolePermissions
            ->merge($directPermissions)
            ->unique()
            ->values()
            ->all();

        return $permissions;
    }

    public static function userHas(?User $user, string $permission): bool
    {
        return in_array($permission, self::forUser($user), true);
    }

    public static function canApplyDiscounts(?User $user): bool
    {
        return self::userHas($user, self::SALES_DISCOUNT_APPLY);
    }

    public static function canUseManualPrice(?User $user): bool
    {
        return self::userHas($user, self::POS_MANUAL_PRICE);
    }

    public static function canViewFelDocuments(?User $user): bool
    {
        return self::userHas($user, self::FEL_DOCUMENTS_VIEW);
    }

    public static function canManagePriceLists(?User $user): bool
    {
        return self::userHas($user, self::PRICE_LISTS_MANAGE);
    }

    public static function canAssignRoles(?User $user): bool
    {
        return self::userHas($user, self::USERS_ASSIGN_ROLES);
    }

    public static function assignableTenantRoles(): array
    {
        if (! Schema::hasTable('roles')) {
            return [];
        }

        $businessId = function_exists('currentBusinessId') ? currentBusinessId() : null;

        return Role::query()
            ->where('key', '!=', 'super_admin')
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id');

                if ($businessId) {
                    $query->orWhere('business_id', $businessId);
                }
            })
            ->when(Schema::hasColumn('roles', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderByRaw('CASE WHEN business_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
    }

    public static function globalAndTenantRoles(?int $businessId)
    {
        if (! Schema::hasTable('roles')) {
            return collect();
        }

        return Role::query()
            ->with('business:id,name')
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id');

                if ($businessId) {
                    $query->orWhere('business_id', $businessId);
                }
            })
            ->when(Schema::hasColumn('roles', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderByRaw('CASE WHEN business_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get();
    }
}
