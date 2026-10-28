<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;

final class WarehouseOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $inventory = (new StockAlertsMetricQuery())->resolve($context, $period);
        $stockSummary = $this->stockSummary($context);
        $transfersAllowed = $context->branchScoped && $context->hasPermission(Permissions::INVENTORY_TRANSFERS_VIEW);
        $transfers = $transfersAllowed ? $this->transfers($context) : null;
        $attention = $this->attention($context, $inventory, $stockSummary);

        return [
            'inventory' => $inventory,
            'stock_summary' => $stockSummary,
            'reservations' => (new ActiveReservationsMetricQuery())->resolve($context, $period),
            'movements_today' => $this->movementsToday($context),
            'movement_trend' => $this->movementTrend($context),
            'top_moved_products' => $this->topMovedProducts($context),
            'critical_stock' => $this->criticalStock($context),
            'transfers' => $transfers,
            'recent_transfers' => $transfersAllowed ? $this->recentTransfers($context) : [],
            'recent_movements' => $this->recentMovements($context),
            'attention' => $attention,
            'total_attention_count' => count($attention),
        ];
    }

    private function stockSummary(DashboardContext $context): array
    {
        $query = Product::query()->where('products.business_id', $context->business->id)->where('products.is_active', true);
        $stock = 'products.stock';

        if ($branchId = $context->branchIdForScopedMetric()) {
            $query->leftJoin('product_branch_stocks as dashboard_stocks', function ($join) use ($context, $branchId) {
                $join->on('dashboard_stocks.product_id', '=', 'products.id')
                    ->where('dashboard_stocks.business_id', '=', $context->business->id)
                    ->where('dashboard_stocks.branch_id', '=', $branchId);
            });
            $stock = 'COALESCE(dashboard_stocks.stock, 0)';
        }

        $summary = $query->selectRaw("COUNT(*) as active_skus, COALESCE(SUM({$stock}), 0) as physical_units, SUM(CASE WHEN {$stock} < 0 THEN 1 ELSE 0 END) as negative_stock_count")->first();

        return [
            'active_skus' => (int) $summary->active_skus,
            'physical_units' => (float) $summary->physical_units,
            'negative_stock_count' => (int) $summary->negative_stock_count,
        ];
    }

    private function movementsToday(DashboardContext $context): array
    {
        $today = $context->period(DashboardPeriod::TODAY);
        $summary = $this->scopedMovements($context)
            ->whereBetween('stock_movements.created_at', [$today->start, $today->end])
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(CASE WHEN stock_movements.quantity > 0 THEN 1 ELSE 0 END) as entries_count')
            ->selectRaw('SUM(CASE WHEN stock_movements.quantity < 0 THEN 1 ELSE 0 END) as exits_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN stock_movements.quantity > 0 THEN stock_movements.quantity ELSE 0 END), 0) as entries_quantity')
            ->selectRaw('ABS(COALESCE(SUM(CASE WHEN stock_movements.quantity < 0 THEN stock_movements.quantity ELSE 0 END), 0)) as exits_quantity')
            ->selectRaw('COALESCE(SUM(stock_movements.quantity), 0) as net_quantity')
            ->first();

        return [
            'count' => (int) $summary->count,
            'entries_count' => (int) $summary->entries_count,
            'exits_count' => (int) $summary->exits_count,
            'entries_quantity' => (float) $summary->entries_quantity,
            'exits_quantity' => (float) $summary->exits_quantity,
            'net_quantity' => (float) $summary->net_quantity,
        ];
    }

    private function movementTrend(DashboardContext $context): array
    {
        $end = now()->timezone($context->timezone)->endOfDay();
        $start = $end->copy()->subDays(13)->startOfDay();
        $period = DashboardPeriod::range(DashboardPeriod::CURRENT, $start, $end);
        $localDate = "DATE((stock_movements.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?)";
        $rows = $this->scopedMovements($context)
            ->whereBetween('stock_movements.created_at', [$period->start, $period->end])
            ->selectRaw("{$localDate} as local_date, COALESCE(SUM(CASE WHEN stock_movements.quantity > 0 THEN stock_movements.quantity ELSE 0 END), 0) as entries, ABS(COALESCE(SUM(CASE WHEN stock_movements.quantity < 0 THEN stock_movements.quantity ELSE 0 END), 0)) as exits, COUNT(*) as movements", [$context->timezone])
            ->groupByRaw('1')
            ->get()
            ->keyBy('local_date');

        return collect(range(0, 13))->map(function (int $offset) use ($start, $rows) {
            $date = $start->copy()->addDays($offset);
            $row = $rows->get($date->toDateString());

            return [
                'date' => $date->toDateString(),
                'label' => $date->locale('es')->translatedFormat('j M'),
                'entries' => (float) ($row->entries ?? 0),
                'exits' => (float) ($row->exits ?? 0),
                'movements' => (int) ($row->movements ?? 0),
            ];
        })->all();
    }

    private function topMovedProducts(DashboardContext $context): array
    {
        $end = now()->timezone($context->timezone)->endOfDay();
        $start = $end->copy()->subDays(29)->startOfDay();
        $period = DashboardPeriod::range(DashboardPeriod::CURRENT, $start, $end);

        return $this->scopedMovements($context)
            ->join('products', function ($join) {
                $join->on('products.id', '=', 'stock_movements.product_id')->whereColumn('products.business_id', 'stock_movements.business_id');
            })
            ->whereBetween('stock_movements.created_at', [$period->start, $period->end])
            ->where('stock_movements.quantity', '!=', 0)
            ->selectRaw('stock_movements.product_id, MAX(products.name) as name, MAX(products.code) as code, COUNT(*) as movements_count, COALESCE(SUM(CASE WHEN stock_movements.quantity > 0 THEN stock_movements.quantity ELSE 0 END), 0) as entries_quantity, ABS(COALESCE(SUM(CASE WHEN stock_movements.quantity < 0 THEN stock_movements.quantity ELSE 0 END), 0)) as exits_quantity, COALESCE(SUM(ABS(stock_movements.quantity)), 0) as total_moved')
            ->groupBy('stock_movements.product_id')
            ->orderByDesc('total_moved')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'code' => $row->code,
                'movements_count' => (int) $row->movements_count,
                'entries_quantity' => (float) $row->entries_quantity,
                'exits_quantity' => (float) $row->exits_quantity,
                'total_moved' => (float) $row->total_moved,
            ])
            ->all();
    }

    private function criticalStock(DashboardContext $context): array
    {
        $query = Product::query()->where('products.business_id', $context->business->id)->where('products.is_active', true)->with('productLocation:id,name');
        $stock = 'products.stock';

        if ($branchId = $context->branchIdForScopedMetric()) {
            $query->leftJoin('product_branch_stocks as dashboard_stocks', function ($join) use ($context, $branchId) {
                $join->on('dashboard_stocks.product_id', '=', 'products.id')
                    ->where('dashboard_stocks.business_id', '=', $context->business->id)
                    ->where('dashboard_stocks.branch_id', '=', $branchId);
            });
            $stock = 'COALESCE(dashboard_stocks.stock, 0)';
        }

        return $query->whereRaw("{$stock} <= products.min_stock")
            ->select('products.*')
            ->selectRaw("{$stock} as dashboard_stock")
            ->orderByRaw("CASE WHEN {$stock} < 0 THEN 0 WHEN {$stock} = 0 THEN 1 ELSE 2 END")
            ->orderByRaw($stock)
            ->orderBy('products.name')
            ->limit(8)
            ->get()
            ->map(function (Product $product) {
                $stock = (float) $product->dashboard_stock;

                return [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'code' => $product->code,
                    'location' => $product->productLocation?->name ?? $product->location,
                    'stock' => $stock,
                    'min_stock' => (float) $product->min_stock,
                    'status' => $stock < 0 ? 'negative' : ($stock === 0.0 ? 'out_of_stock' : 'low_stock'),
                ];
            })
            ->all();
    }

    private function transfers(DashboardContext $context): array
    {
        $branchId = $context->branchIdForScopedMetric();
        if (! $branchId) return [];

        $month = $this->monthToToday($context);
        $base = $this->scopedTransfers($context)->whereBetween('inventory_transfers.created_at', [$month->start, $month->end]);
        $lines = $this->scopedTransferLines($context)->whereBetween('inventory_transfers.created_at', [$month->start, $month->end]);

        return [
            'month_count' => (int) (clone $base)->count(),
            'inbound_count' => (int) (clone $base)->where('inventory_transfers.to_branch_id', $branchId)->count(),
            'outbound_count' => (int) (clone $base)->where('inventory_transfers.from_branch_id', $branchId)->count(),
            'inbound_units' => (float) (clone $lines)->where('inventory_transfers.to_branch_id', $branchId)->sum('inventory_transfer_lines.quantity'),
            'outbound_units' => (float) (clone $lines)->where('inventory_transfers.from_branch_id', $branchId)->sum('inventory_transfer_lines.quantity'),
        ];
    }

    private function recentTransfers(DashboardContext $context): array
    {
        $branchId = $context->branchIdForScopedMetric();
        if (! $branchId) return [];

        return $this->scopedTransfers($context)
            ->with(['fromBranch:id,name', 'toBranch:id,name'])
            ->withCount('lines')
            ->withSum('lines as quantity', 'quantity')
            ->latest('inventory_transfers.created_at')
            ->limit(5)
            ->get()
            ->map(function (InventoryTransfer $transfer) use ($branchId) {
                $inbound = (int) $transfer->to_branch_id === $branchId;

                return [
                    'id' => $transfer->id,
                    'direction' => $inbound ? 'inbound' : 'outbound',
                    'other_branch' => $inbound ? $transfer->fromBranch?->name : $transfer->toBranch?->name,
                    'quantity' => (float) ($transfer->quantity ?? 0),
                    'lines_count' => (int) $transfer->lines_count,
                    'created_at' => $transfer->created_at?->timezone($context->timezone)->toIso8601String(),
                    'href' => route('inventory.transfers.show', $transfer, absolute: false),
                ];
            })
            ->all();
    }

    private function recentMovements(DashboardContext $context): array
    {
        return $this->scopedMovements($context)
            ->with('product:id,name,code')
            ->latest('stock_movements.created_at')
            ->limit(6)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'product_id' => $movement->product_id,
                'product_name' => $movement->product?->name,
                'product_code' => $movement->product?->code,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'previous_stock' => $movement->previous_stock,
                'new_stock' => $movement->new_stock,
                'note' => $movement->note,
                'created_at' => $movement->created_at?->timezone($context->timezone)->toIso8601String(),
                'time' => $movement->created_at?->timezone($context->timezone)->format('H:i'),
            ])
            ->all();
    }

    private function attention(DashboardContext $context, array $inventory, array $stockSummary): array
    {
        $href = $context->hasModule('reports') && $context->hasPermission(Permissions::REPORTS_LOW_STOCK_VIEW)
            ? route('reports.low-stock', absolute: false)
            : route('stock.index', absolute: false);
        $items = [];
        $negative = $stockSummary['negative_stock_count'];
        $zero = max(0, $inventory['out_of_stock_count'] - $negative);

        if ($negative > 0) $items[] = $this->item('stock.negative', 'critical', "{$negative} productos tienen stock negativo.", 'Revisa el inventario.', $href);
        if ($zero > 0) $items[] = $this->item('stock.out', 'critical', "{$zero} productos sin stock.", 'Revisa el inventario.', $href);
        if ($inventory['low_stock_count'] > 0) $items[] = $this->item('stock.low', 'warning', "{$inventory['low_stock_count']} productos con stock bajo.", 'Revisa el inventario.', $href);
        usort($items, fn (array $left, array $right) => ['critical' => 0, 'warning' => 1, 'info' => 2][$left['severity']] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$right['severity']]);

        return array_slice($items, 0, 6);
    }

    private function scopedMovements(DashboardContext $context)
    {
        return StockMovement::query()
            ->where('stock_movements.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('stock_movements.branch_id', $branchId));
    }

    private function scopedTransfers(DashboardContext $context)
    {
        $branchId = $context->branchIdForScopedMetric();

        return InventoryTransfer::query()
            ->where('inventory_transfers.business_id', $context->business->id)
            ->where(fn ($query) => $query->where('inventory_transfers.status', 'completed')->orWhereNull('inventory_transfers.status'))
            ->where(fn ($query) => $query->where('inventory_transfers.from_branch_id', $branchId)->orWhere('inventory_transfers.to_branch_id', $branchId));
    }

    private function scopedTransferLines(DashboardContext $context)
    {
        $branchId = $context->branchIdForScopedMetric();

        return DB::table('inventory_transfer_lines')
            ->join('inventory_transfers', function ($join) {
                $join->on('inventory_transfers.id', '=', 'inventory_transfer_lines.inventory_transfer_id')
                    ->whereColumn('inventory_transfers.business_id', 'inventory_transfer_lines.business_id');
            })
            ->where('inventory_transfer_lines.business_id', $context->business->id)
            ->where('inventory_transfers.business_id', $context->business->id)
            ->where(fn ($query) => $query->where('inventory_transfers.status', 'completed')->orWhereNull('inventory_transfers.status'))
            ->where(fn ($query) => $query->where('inventory_transfers.from_branch_id', $branchId)->orWhere('inventory_transfers.to_branch_id', $branchId));
    }

    private function monthToToday(DashboardContext $context): DashboardPeriod
    {
        $now = now()->timezone($context->timezone);

        return DashboardPeriod::range(DashboardPeriod::THIS_MONTH, $now->copy()->startOfMonth(), $now->copy()->endOfDay());
    }

    private function item(string $key, string $severity, string $title, string $detail, string $href): array
    {
        return compact('key', 'severity', 'title', 'detail', 'href');
    }
}
