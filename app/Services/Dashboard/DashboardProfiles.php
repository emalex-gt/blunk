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
            'executive' => ['title' => 'Resumen ejecutivo', 'description' => 'Estado general y señales clave del negocio.'],
            'branch_manager' => ['title' => 'Operación de sucursal', 'description' => 'Ventas, caja, inventario y operación de la sucursal.'],
            'cashier' => ['title' => 'Mi caja', 'description' => 'Accesos y estado de tu operación de venta.'],
            'route_seller' => ['title' => 'Mi jornada', 'description' => 'Accede rápidamente a tus rutas y trabajo del día.'],
            'route_delivery' => ['title' => 'Mis entregas', 'description' => 'Accede a tus jornadas y entregas asignadas.'],
            'route_supervisor' => ['title' => 'Supervisión de rutas', 'description' => 'Estado operativo de rutas, visitas, entregas y liquidaciones.'],
            'warehouse' => ['title' => 'Operación de inventario', 'description' => 'Stock, reservas y tareas de almacén.'],
            'purchases' => ['title' => 'Compras', 'description' => 'Accesos principales para compras y abastecimiento.'],
            'finance' => ['title' => 'Análisis financiero', 'description' => 'Ventas, rentabilidad, cartera y reportes disponibles.'],
            'generic' => ['title' => 'Inicio', 'description' => 'Accesos disponibles según tus permisos.'],
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
            'branch_manager' => [
                'sales.today',
                'sales.this_month',
                'cash.current',
                'stock.alerts',
                'sales.top_product',
                'sales.estimated_margin',
            ],
            'cashier' => ['cashier.workspace'],
            'route_seller' => ['routes.my_work_day'],
            'route_delivery' => ['routes.my_delivery_run'],
            'purchases', 'generic' => [],
            'warehouse' => [
                'stock.alerts',
                'stock.active_reservations',
            ],
            'finance' => [
                'sales.today',
                'sales.this_month',
                'sales.estimated_margin',
                'sales.top_product',
                'credits.receivable',
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
