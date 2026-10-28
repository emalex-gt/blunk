<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Product;
use App\Models\Purchase;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PurchasesOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $month = $this->monthToToday($context);
        $previous = $this->previousComparableMonth($context);
        $inventoryAllowed = $context->hasModule('inventory') && $context->hasPermission(Permissions::INVENTORY_VIEW);
        $purchasesMonth = $this->summary($context, $month, $previous);
        $topSuppliers = $this->topSuppliers($context, $month, (float) $purchasesMonth['total']);
        $costChanges = $this->costChanges($context);
        $stock = $inventoryAllowed ? (new StockAlertsMetricQuery())->resolve($context, $period) : null;

        return [
            'purchases_month' => $purchasesMonth,
            'purchase_trend' => $this->trend($context),
            'top_suppliers' => $topSuppliers,
            'top_products' => $this->topProducts($context, $month),
            'replenishment' => $inventoryAllowed ? $this->replenishment($context) : [],
            'stock' => $stock,
            'cost_changes' => $costChanges,
            'recent_purchases' => $this->recentPurchases($context),
            'payment_mix' => $this->paymentMix($context, $month),
            'attention' => $this->attention($stock, $topSuppliers, $purchasesMonth, $costChanges),
        ];
    }

    private function summary(DashboardContext $context, DashboardPeriod $month, DashboardPeriod $previous): array
    {
        $current = $this->purchasesQuery($context, $month)->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total, COUNT(DISTINCT supplier_id) as supplier_count')->first();
        $itemsQuantity = (float) $this->purchaseItemsQuery($context, $month)->sum('purchase_items.quantity');
        $prior = (float) $this->purchasesQuery($context, $previous)->sum('total');
        $total = (float) $current->total;
        $count = (int) $current->count;

        return [
            'total' => $this->money($total),
            'count' => $count,
            'average' => $this->money($count ? $total / $count : 0),
            'items_quantity' => $itemsQuantity,
            'supplier_count' => (int) $current->supplier_count,
            'previous_total' => $this->money($prior),
            'change_percent' => $this->change($total, $prior),
        ];
    }

    private function trend(DashboardContext $context): array
    {
        $current = now()->timezone($context->timezone)->startOfMonth();
        $start = $current->copy()->subMonths(5);
        $period = DashboardPeriod::range(DashboardPeriod::CURRENT, $start, now()->timezone($context->timezone)->endOfDay());
        $month = "TO_CHAR((purchases.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM')";
        $rows = $this->purchasesQuery($context, $period)
            ->selectRaw("{$month} as local_month, COUNT(*) as count, COALESCE(SUM(purchases.total), 0) as total", [$context->timezone])
            ->groupByRaw('1')
            ->get()
            ->keyBy('local_month');

        return collect(range(0, 5))->map(function (int $offset) use ($start, $rows) {
            $date = $start->copy()->addMonths($offset);
            $row = $rows->get($date->format('Y-m'));

            return ['month' => $date->format('Y-m'), 'label' => mb_strtolower($date->locale('es')->translatedFormat('M')), 'total' => round((float) ($row->total ?? 0), 2), 'count' => (int) ($row->count ?? 0)];
        })->all();
    }

    private function topSuppliers(DashboardContext $context, DashboardPeriod $month, float $monthTotal): array
    {
        return $this->purchasesQuery($context, $month)
            ->join('suppliers', function ($join) {
                $join->on('suppliers.id', '=', 'purchases.supplier_id')->whereColumn('suppliers.business_id', 'purchases.business_id');
            })
            ->whereNotNull('purchases.supplier_id')
            ->selectRaw('purchases.supplier_id, MAX(suppliers.name) as name, COUNT(*) as purchases_count, COALESCE(SUM(purchases.total), 0) as total')
            ->groupBy('purchases.supplier_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['supplier_id' => (int) $row->supplier_id, 'name' => $row->name, 'purchases_count' => (int) $row->purchases_count, 'total' => (float) $row->total, 'percentage' => $monthTotal > 0 ? round(((float) $row->total / $monthTotal) * 100, 2) : 0])
            ->all();
    }

    private function topProducts(DashboardContext $context, DashboardPeriod $month): array
    {
        return $this->purchaseItemsQuery($context, $month)
            ->selectRaw('purchase_items.product_id, MAX(purchase_items.product_name) as name, COALESCE(SUM(purchase_items.quantity), 0) as quantity, COALESCE(SUM(purchase_items.total), 0) as total')
            ->groupBy('purchase_items.product_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['product_id' => (int) $row->product_id, 'name' => $row->name, 'quantity' => (float) $row->quantity, 'total' => (float) $row->total])
            ->all();
    }

    private function replenishment(DashboardContext $context): array
    {
        $products = Product::query()->where('products.business_id', $context->business->id)->where('products.is_active', true);
        $stock = 'products.stock';

        if ($branchId = $context->branchIdForScopedMetric()) {
            $products->leftJoin('product_branch_stocks as pbs', function ($join) use ($context, $branchId) {
                $join->on('pbs.product_id', '=', 'products.id')->where('pbs.business_id', '=', $context->business->id)->where('pbs.branch_id', '=', $branchId);
            });
            $stock = 'COALESCE(pbs.stock, 0)';
        }

        $rows = $products->whereRaw("{$stock} <= products.min_stock")
            ->selectRaw("products.id, products.name, products.code, products.min_stock, {$stock} as stock")
            ->orderByRaw("CASE WHEN {$stock} <= 0 THEN 0 ELSE 1 END")
            ->orderByRaw($stock)
            ->orderBy('products.name')
            ->limit(8)
            ->get();
        $lastCosts = $this->lastCosts($context, $rows->pluck('id')->all());

        return $rows->map(function ($product) use ($lastCosts) {
            $last = $lastCosts->get($product->id);
            $stock = (float) $product->stock;

            return [
                'product_id' => (int) $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'stock' => $stock,
                'min_stock' => (float) $product->min_stock,
                'status' => $stock <= 0 ? 'out_of_stock' : 'low_stock',
                'last_purchase_cost' => $last ? (float) $last->unit_cost : null,
                'last_purchase_at' => $last?->created_at,
            ];
        })->all();
    }

    private function lastCosts(DashboardContext $context, array $productIds)
    {
        if ($productIds === []) return collect();

        $ranked = $this->nativePurchaseItems($context)
            ->whereIn('purchase_items.product_id', $productIds)
            ->selectRaw('purchase_items.product_id, purchase_items.unit_cost, purchases.created_at, ROW_NUMBER() OVER (PARTITION BY purchase_items.product_id ORDER BY purchases.created_at DESC, purchase_items.id DESC) as rn');

        $rows = DB::query()->fromSub($ranked, 'ranked_costs')->where('rn', 1)->get();

        return $rows->keyBy('product_id');
    }

    private function costChanges(DashboardContext $context): array
    {
        $ranked = $this->nativePurchaseItems($context)
            ->selectRaw('purchase_items.product_id, purchase_items.product_name, purchase_items.unit_cost, purchases.created_at, ROW_NUMBER() OVER (PARTITION BY purchase_items.product_id ORDER BY purchases.created_at DESC, purchase_items.id DESC) as rn');

        $observations = DB::query()->fromSub($ranked, 'ranked_costs')
            ->whereIn('rn', [1, 2])
            ->get()
            ->groupBy('product_id');

        return $observations->map(function ($rows) {
            $latest = $rows->firstWhere('rn', 1);
            $previous = $rows->firstWhere('rn', 2);
            if (! $latest || ! $previous || (float) $latest->unit_cost === (float) $previous->unit_cost) return null;
            $change = (float) $latest->unit_cost - (float) $previous->unit_cost;

            return [
                'product_id' => (int) $latest->product_id,
                'name' => $latest->product_name,
                'latest_cost' => (float) $latest->unit_cost,
                'previous_cost' => (float) $previous->unit_cost,
                'change_amount' => $change,
                'change_percent' => (float) $previous->unit_cost > 0 ? round(($change / (float) $previous->unit_cost) * 100, 2) : null,
                'latest_purchase_at' => $latest->created_at,
            ];
        })->filter()->sort(function (array $left, array $right) {
            $change = abs($right['change_percent'] ?? 0) <=> abs($left['change_percent'] ?? 0);

            return $change !== 0 ? $change : strcmp((string) $right['latest_purchase_at'], (string) $left['latest_purchase_at']);
        })->take(6)->values()->all();
    }

    private function recentPurchases(DashboardContext $context): array
    {
        return $this->purchasesQuery($context, null)
            ->with('supplier:id,name')
            ->latest()
            ->limit(6)
            ->get(['id', 'business_id', 'supplier_id', 'business_number', 'purchase_number', 'total', 'payment_method', 'created_at'])
            ->map(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'business_number' => $purchase->business_number ?? $purchase->purchase_number,
                'supplier_name' => $purchase->supplier?->name ?? 'Sin proveedor',
                'total' => (float) $purchase->total,
                'payment_method' => $this->paymentMethodLabel((string) $purchase->payment_method),
                'created_at' => $purchase->created_at?->timezone($context->timezone)->format('Y-m-d H:i'),
                'href' => route('purchases.show', $purchase, absolute: false),
            ])
            ->all();
    }

    private function paymentMix(DashboardContext $context, DashboardPeriod $month): array
    {
        $rows = $this->purchasesQuery($context, $month)->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(total), 0) as total')->groupBy('payment_method')->get()
            ->groupBy(fn ($row) => $this->paymentMethodKey((string) $row->payment_method))
            ->map(fn ($purchases, string $key) => ['method' => $key, 'label' => $this->paymentMethodLabel($key), 'total' => round((float) $purchases->sum('total'), 2), 'count' => (int) $purchases->sum('count')])
            ->sortByDesc('total')->values();
        $total = (float) $rows->sum('total');

        return $rows->map(fn (array $row) => [...$row, 'percentage' => $total > 0 ? round(($row['total'] / $total) * 100, 2) : 0])->all();
    }

    private function attention(?array $stock, array $topSuppliers, array $summary, array $costChanges): array
    {
        $items = [];
        if (($stock['out_of_stock_count'] ?? 0) > 0) $items[] = ['key' => 'stock.out', 'severity' => 'critical', 'title' => "{$stock['out_of_stock_count']} productos sin stock", 'detail' => 'Revisa la reposición.'];
        if (($stock['low_stock_count'] ?? 0) > 0) $items[] = ['key' => 'stock.low', 'severity' => 'warning', 'title' => "{$stock['low_stock_count']} productos con stock bajo", 'detail' => 'Revisa la reposición.'];
        if (($top = $topSuppliers[0] ?? null) && $summary['count'] >= 3 && $top['percentage'] >= 60) $items[] = ['key' => 'supplier.concentration', 'severity' => 'info', 'title' => "El {$top['percentage']}% de las compras del mes está concentrado en {$top['name']}.", 'detail' => 'Señal de concentración de proveedor.'];
        if (collect($costChanges)->contains(fn (array $change) => ($change['change_percent'] ?? 0) >= 10)) $items[] = ['key' => 'cost.increase', 'severity' => 'warning', 'title' => 'Hay productos con aumentos recientes de costo.', 'detail' => 'Revisa los cambios de costo.'];
        usort($items, fn (array $left, array $right) => ['critical' => 0, 'warning' => 1, 'info' => 2][$left['severity']] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$right['severity']]);

        return array_slice($items, 0, 6);
    }

    private function purchasesQuery(DashboardContext $context, ?DashboardPeriod $period): Builder
    {
        return Purchase::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->when($period, fn ($query, DashboardPeriod $range) => $query->whereBetween('created_at', [$range->start, $range->end]));
    }

    private function purchaseItemsQuery(DashboardContext $context, DashboardPeriod $period)
    {
        return DB::table('purchase_items')
            ->join('purchases', function ($join) { $join->on('purchases.id', '=', 'purchase_items.purchase_id')->whereColumn('purchases.business_id', 'purchase_items.business_id'); })
            ->where('purchase_items.business_id', $context->business->id)
            ->where('purchases.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('purchases.branch_id', $branchId))
            ->where(fn ($query) => $query->where('purchases.status', 'completed')->orWhereNull('purchases.status'))
            ->whereBetween('purchases.created_at', [$period->start, $period->end]);
    }

    private function nativePurchaseItems(DashboardContext $context)
    {
        return DB::table('purchase_items')
            ->join('purchases', function ($join) { $join->on('purchases.id', '=', 'purchase_items.purchase_id')->whereColumn('purchases.business_id', 'purchase_items.business_id'); })
            ->where('purchase_items.business_id', $context->business->id)
            ->where('purchases.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('purchases.branch_id', $branchId))
            ->where(fn ($query) => $query->where('purchases.status', 'completed')->orWhereNull('purchases.status'));
    }

    private function previousComparableMonth(DashboardContext $context): DashboardPeriod
    {
        $now = now()->timezone($context->timezone);
        $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->addDays(min($now->day, $start->daysInMonth) - 1)->endOfDay();
        return DashboardPeriod::range(DashboardPeriod::CURRENT, $start, $end);
    }

    private function monthToToday(DashboardContext $context): DashboardPeriod
    {
        $now = now()->timezone($context->timezone);

        return DashboardPeriod::range(DashboardPeriod::THIS_MONTH, $now->copy()->startOfMonth(), $now->copy()->endOfDay());
    }

    private function paymentMethodKey(string $method): string { return match ($method) { 'cash', 'card', 'check', 'credit' => $method, 'bank_transfer', 'transfer' => 'transfer', default => 'other' }; }
    private function paymentMethodLabel(string $method): string { return match ($method) { 'cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'check' => 'Cheque', 'credit' => 'Crédito', default => 'Otro' }; }
    private function change(float $current, float $previous): ?float { return $previous > 0 ? round((($current - $previous) / $previous) * 100, 2) : null; }
    private function money(float $value): string { return number_format($value, 2, '.', ''); }
}
