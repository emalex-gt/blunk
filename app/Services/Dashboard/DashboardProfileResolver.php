<?php

namespace App\Services\Dashboard;

use App\Support\Permissions;

final class DashboardProfileResolver
{
    public function resolve(DashboardContext $context): string
    {
        $canSuperviseRoutes = $context->hasModule('routes')
            && $context->hasPermission(Permissions::ROUTES_VIEW)
            && $context->hasPermission(Permissions::ROUTES_PRE_SALES_ADMIN_VIEW)
            && $context->hasPermission(Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $canRunWarehouse = $context->hasModule('inventory')
            && $context->hasPermission(Permissions::INVENTORY_VIEW);
        $canRunExecutive = $context->hasModule('pos')
            && $context->hasModule('reports')
            && $context->hasPermission(Permissions::REPORTS_SALES_VIEW);

        // A profile is a presentation preference, not an authorization grant.
        if ($canSuperviseRoutes) {
            return 'route_supervisor';
        }

        $roleKeys = $context->user->roles()->pluck('key')->all();
        if ($roleKeys === [] && filled($context->user->role)) {
            $roleKeys[] = $context->user->role;
        }

        if (in_array('stock_manager', $roleKeys, true) && $canRunWarehouse) {
            return 'warehouse';
        }

        if (array_intersect(['owner', 'admin', 'reports'], $roleKeys) !== [] && $canRunExecutive) {
            return 'executive';
        }

        if (array_intersect(['owner', 'admin', 'reports'], $roleKeys) !== []) {
            return 'owner_simple';
        }

        // Unknown/custom roles are selected solely through effective capability.
        if ($canRunWarehouse) {
            return 'warehouse';
        }

        if ($canRunExecutive) {
            return 'executive';
        }

        return 'owner_simple';
    }
}
