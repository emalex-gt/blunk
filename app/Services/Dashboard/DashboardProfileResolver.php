<?php

namespace App\Services\Dashboard;

use App\Support\Permissions;

final class DashboardProfileResolver
{
    public function resolve(DashboardContext $context): string
    {
        $capabilities = [
            'executive' => $context->hasModule('pos')
                && $context->hasModule('reports')
                && $context->hasPermission(Permissions::REPORTS_SALES_VIEW),
            'branch_manager' => $context->hasModule('pos')
                && $context->hasPermission(Permissions::POS_VIEW),
            'cashier' => $context->hasModule('pos')
                && $context->hasPermission(Permissions::POS_VIEW),
            'route_seller' => $context->hasModule('routes')
                && $context->hasPermission(Permissions::ROUTES_WORK),
            'route_delivery' => $context->hasModule('routes')
                && $context->hasPermission(Permissions::ROUTES_DELIVERY_RUNS_VIEW),
            'route_supervisor' => $context->hasModule('routes')
                && $context->hasPermission(Permissions::ROUTES_VIEW)
                && $context->hasPermission(Permissions::ROUTES_PRE_SALES_ADMIN_VIEW)
                && $context->hasPermission(Permissions::ROUTES_DELIVERY_RUNS_VIEW),
            'warehouse' => $context->hasModule('inventory')
                && $context->hasPermission(Permissions::INVENTORY_VIEW),
            'purchases' => $context->hasModule('purchases')
                && $context->hasPermission(Permissions::PURCHASES_VIEW),
            'finance' => $context->hasModule('reports')
                && $context->hasPermission(Permissions::REPORTS_SALES_VIEW),
        ];

        // Standard roles are presentation preferences only. In particular, this
        // happens before capability fallback so owner/admin never become route supervisors.
        $standardProfiles = [
            'owner' => 'executive',
            'admin' => 'branch_manager',
            'cashier' => 'cashier',
            'pre_seller' => 'route_seller',
            'delivery_agent' => 'route_delivery',
            'stock_manager' => 'warehouse',
            'purchases' => 'purchases',
            'reports' => 'finance',
        ];
        $roleKeys = $context->user->roles()->pluck('key')->all();
        $standardRole = array_key_exists((string) $context->user->role, $standardProfiles)
            ? (string) $context->user->role
            : collect($roleKeys)->first(fn (string $role) => array_key_exists($role, $standardProfiles));
        $preferred = $standardRole ? $standardProfiles[$standardRole] : null;

        if ($preferred && $capabilities[$preferred]) {
            return $preferred;
        }

        if (in_array($standardRole, ['owner', 'admin'], true) && $capabilities['branch_manager']) {
            return 'branch_manager';
        }

        // Custom and incompatible standard roles use the same deterministic
        // capability precedence. A profile never adds authority.
        foreach (['executive', 'finance', 'route_supervisor', 'warehouse', 'purchases', 'route_seller', 'route_delivery', 'cashier'] as $profile) {
            if ($capabilities[$profile]) {
                return $profile;
            }
        }

        return 'generic';
    }
}
