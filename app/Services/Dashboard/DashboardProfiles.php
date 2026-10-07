<?php

namespace App\Services\Dashboard;

use InvalidArgumentException;

final class DashboardProfiles
{
    /** @return array{title:string,description:string} */
    public static function metadata(string $profile): array
    {
        return match ($profile) {
            'owner_simple' => ['title' => 'Información operativa', 'description' => 'Resumen del negocio para la operación actual.'],
            'executive' => ['title' => 'Resumen ejecutivo', 'description' => 'Ventas, rentabilidad y señales clave del negocio.'],
            'warehouse' => ['title' => 'Operación de inventario', 'description' => 'Disponibilidad y reservas activas de la sucursal.'],
            'route_supervisor' => ['title' => 'Supervisión de rutas', 'description' => 'Jornadas, preventas, entregas y efectivo de rutas.'],
            default => throw new InvalidArgumentException("Unsupported dashboard profile [{$profile}]."),
        };
    }

    /** @return array<int, string> */
    public static function widgetKeys(string $profile): array
    {
        return match ($profile) {
            'owner_simple' => [
                'sales.today',
                'sales.this_month',
                'cash.current',
                'stock.alerts',
                'sales.top_product',
                'sales.estimated_margin',
            ],
            'executive' => [
                'sales.today',
                'sales.this_month',
                'sales.estimated_margin',
                'sales.top_product',
                'stock.alerts',
                'credits.receivable',
            ],
            'warehouse' => [
                'stock.alerts',
                'stock.active_reservations',
            ],
            'route_supervisor' => [
                'routes.open_work_days',
                'routes.pending_visits',
                'routes.operational_pre_sales',
                'routes.pending_delivery_stops',
                'routes.effective_settlements',
            ],
            default => throw new InvalidArgumentException("Unsupported dashboard profile [{$profile}]."),
        };
    }
}
