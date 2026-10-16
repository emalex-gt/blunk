<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\StockMovement;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;

final class WarehouseOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $inventory = (new StockAlertsMetricQuery())->resolve($context, $period);
        $reservations = (new ActiveReservationsMetricQuery())->resolve($context, $period);
        $movements = $this->movements($context);
        $attention = $this->attention($context, $inventory);

        return [
            'inventory' => $inventory,
            'reservations' => $reservations,
            'movements_today_count' => $this->movementsTodayCount($context),
            'recent_movements' => $movements,
            'attention' => $attention,
            'total_attention_count' => count($attention),
            'actions' => $this->actions($context),
        ];
    }

    private function movements(DashboardContext $context): array
    {
        return $this->scopedMovements($context)
            ->with('product:id,name,code')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'product_id' => $movement->product_id,
                'product_name' => $movement->product?->name,
                'product_code' => $movement->product?->code,
                'quantity' => $movement->quantity,
                'previous_stock' => $movement->previous_stock,
                'new_stock' => $movement->new_stock,
                'note' => $movement->note,
                'created_at' => $movement->created_at?->timezone($context->timezone)->toIso8601String(),
                'time' => $movement->created_at?->timezone($context->timezone)->format('H:i'),
            ])
            ->all();
    }

    private function movementsTodayCount(DashboardContext $context): int
    {
        $today = $context->period(DashboardPeriod::TODAY);

        return $this->scopedMovements($context)
            ->whereBetween('created_at', [$today->start, $today->end])
            ->count();
    }

    private function scopedMovements(DashboardContext $context)
    {
        return StockMovement::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId));
    }

    private function attention(DashboardContext $context, array $inventory): array
    {
        $href = $context->hasModule('reports') && $context->hasPermission(Permissions::REPORTS_LOW_STOCK_VIEW)
            ? route('reports.low-stock', absolute: false)
            : route('stock.index', absolute: false);
        $items = [];

        if ($inventory['out_of_stock_count'] > 0) {
            $items[] = $this->item('stock.out', 'critical', "{$inventory['out_of_stock_count']} productos sin stock", 'Revisa el inventario.', $href);
        }
        if ($inventory['low_stock_count'] > 0) {
            $items[] = $this->item('stock.low', 'warning', "{$inventory['low_stock_count']} productos con stock bajo", 'Revisa el inventario.', $href);
        }

        return $items;
    }

    private function actions(DashboardContext $context): array
    {
        $inventoryLabel = $context->hasPermission(Permissions::INVENTORY_ADJUST) ? 'Ajustar stock' : 'Ver inventario';
        $definitions = [
            ['stock.index', $inventoryLabel, 'stock.index', 'primary', ['inventory'], [Permissions::INVENTORY_VIEW]],
            ['inventory.transfers.create', 'Nuevo traslado', 'inventory.transfers.create', 'secondary', ['inventory'], [Permissions::INVENTORY_TRANSFERS_CREATE]],
            ['inventory.transfers.index', 'Ver traslados', 'inventory.transfers.index', 'secondary', ['inventory'], [Permissions::INVENTORY_TRANSFERS_VIEW]],
            ['products.index', 'Productos', 'products.index', 'secondary', ['inventory'], [Permissions::PRODUCTS_VIEW]],
            ['reports.low-stock', 'Stock bajo', 'reports.low-stock', 'secondary', ['reports'], [Permissions::REPORTS_LOW_STOCK_VIEW]],
            ['product-locations.index', 'Ubicaciones', 'product-locations.index', 'secondary', ['inventory'], [Permissions::PRODUCT_LOCATIONS_VIEW]],
        ];

        return collect($definitions)
            ->filter(fn (array $definition) => collect($definition[4])->every(fn (string $module) => $context->hasModule($module))
                && collect($definition[5])->every(fn (string $permission) => $context->hasPermission($permission)))
            ->map(fn (array $definition) => [
                'key' => $definition[0],
                'label' => $definition[1],
                'href' => route($definition[2], absolute: false),
                'emphasis' => $definition[3],
            ])
            ->values()
            ->all();
    }

    private function item(string $key, string $severity, string $title, string $detail, string $href): array
    {
        return compact('key', 'severity', 'title', 'detail', 'href');
    }
}
