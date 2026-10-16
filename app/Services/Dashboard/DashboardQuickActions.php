<?php

namespace App\Services\Dashboard;

use App\Support\Permissions;

final class DashboardQuickActions
{
    /** @return array<int, array{key:string,label:string,href:string,emphasis:string}> */
    public static function for(DashboardContext $context, string $profile): array
    {
        $actions = [];

        foreach (self::definitions() as $definition) {
            if (! in_array($profile, $definition['profiles'], true) || ! self::isAllowed($context, $definition)) {
                continue;
            }

            $actions[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'href' => route($definition['route'], absolute: false),
                'emphasis' => $definition['emphasis'],
            ];
        }

        return $actions;
    }

    /** @return array<int, array{key:string,label:string,route:string,profiles:array<int,string>,modules:array<int,string>,permissions:array<int,string>,features:array<int,string>,emphasis:string}> */
    private static function definitions(): array
    {
        $operational = ['executive', 'generic'];

        return [
            ['key' => 'sales.create', 'label' => 'Nueva venta', 'route' => 'sales.create', 'profiles' => $operational, 'modules' => ['pos'], 'permissions' => [Permissions::POS_VIEW], 'features' => [], 'emphasis' => 'primary'],
            ['key' => 'cash-register.index', 'label' => 'Ver caja', 'route' => 'cash-register.index', 'profiles' => ['executive', 'generic'], 'modules' => ['cash_register'], 'permissions' => [Permissions::CASH_REGISTER_VIEW], 'emphasis' => 'secondary'],
            ['key' => 'routes.mobile.zones', 'label' => 'Mis rutas', 'route' => 'routes.mobile.zones', 'profiles' => ['generic'], 'modules' => ['routes'], 'permissions' => [Permissions::ROUTES_WORK], 'emphasis' => 'primary'],
            ['key' => 'routes.mobile.post-conversion-collections', 'label' => 'Cobros pendientes', 'route' => 'routes.mobile.post-conversion-collections.index', 'profiles' => ['generic'], 'modules' => ['routes'], 'permissions' => [Permissions::ROUTES_POST_CONVERSION_COLLECTIONS_COLLECT], 'emphasis' => 'secondary'],
            ['key' => 'routes.delivery-runs', 'label' => 'Mis entregas', 'route' => 'routes.delivery-runs.index', 'profiles' => ['generic'], 'modules' => ['routes'], 'permissions' => [Permissions::ROUTES_DELIVERY_RUNS_VIEW], 'emphasis' => 'primary'],
            ['key' => 'routes.global-operations', 'label' => 'Operación de rutas', 'route' => 'routes.global-operations.index', 'profiles' => ['route_supervisor', 'executive', 'generic'], 'modules' => ['routes'], 'permissions' => [Permissions::ROUTES_PRE_SALES_ADMIN_VIEW], 'emphasis' => 'primary'],
            ['key' => 'routes.pre-sales', 'label' => 'Preventas', 'route' => 'routes.pre-sales.index', 'profiles' => ['route_supervisor', 'generic'], 'modules' => ['routes'], 'permissions' => [Permissions::ROUTES_PRE_SALES_ADMIN_VIEW], 'emphasis' => 'secondary'],
            ['key' => 'routes.cash-settlements', 'label' => 'Liquidaciones', 'route' => 'routes.cash-settlements.index', 'profiles' => ['route_supervisor', 'generic'], 'modules' => ['routes'], 'permissions' => [Permissions::ROUTES_CASH_SETTLEMENTS_VIEW], 'emphasis' => 'secondary'],
            ['key' => 'stock.index', 'label' => 'Inventario', 'route' => 'stock.index', 'profiles' => ['generic'], 'modules' => ['inventory'], 'permissions' => [Permissions::INVENTORY_VIEW], 'emphasis' => 'primary'],
            ['key' => 'inventory.transfers', 'label' => 'Traslados', 'route' => 'inventory.transfers.index', 'profiles' => ['generic'], 'modules' => ['inventory'], 'permissions' => [Permissions::INVENTORY_TRANSFERS_VIEW], 'emphasis' => 'secondary'],
            ['key' => 'purchases.create', 'label' => 'Nueva compra', 'route' => 'purchases.create', 'profiles' => ['purchases', 'generic'], 'modules' => ['purchases'], 'permissions' => [Permissions::PURCHASES_CREATE], 'emphasis' => 'primary'],
            ['key' => 'purchases.index', 'label' => 'Ver compras', 'route' => 'purchases.index', 'profiles' => ['purchases', 'generic'], 'modules' => ['purchases'], 'permissions' => [Permissions::PURCHASES_VIEW], 'emphasis' => 'secondary'],
            ['key' => 'reports.sales', 'label' => 'Reportes de ventas', 'route' => 'reports.sales', 'profiles' => ['executive', 'finance', 'generic'], 'modules' => ['reports'], 'permissions' => [Permissions::REPORTS_SALES_VIEW], 'emphasis' => 'primary'],
            ['key' => 'credits.accounts', 'label' => 'Cuentas por cobrar', 'route' => 'credits.accounts.index', 'profiles' => ['executive', 'finance', 'generic'], 'modules' => ['credits'], 'permissions' => [Permissions::CREDITS_ACCOUNTS_VIEW], 'features' => ['credit_sales'], 'emphasis' => 'secondary'],
        ];
    }

    /** @param array{modules:array<int,string>,permissions:array<int,string>,features?:array<int,string>} $definition */
    private static function isAllowed(DashboardContext $context, array $definition): bool
    {
        return collect($definition['modules'])->every(fn (string $module) => $context->hasModule($module))
            && collect($definition['permissions'])->every(fn (string $permission) => $context->hasPermission($permission))
            && collect($definition['features'] ?? [])->every(fn (string $feature) => $context->hasFeature($feature));
    }
}
