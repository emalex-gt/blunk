<?php

namespace App\Support;

use App\Models\CreditReceiptLine;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\StockReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockAvailability
{
    public static function getBreakdownForProducts(int $businessId, int $branchId, array $productIds): Collection
    {
        $productIds = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return collect();
        }

        $physicalStock = ProductBranchStock::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->pluck('stock', 'product_id')
            ->map(fn ($quantity) => (float) $quantity);

        $reservedPreSales = StockReservation::query()
            ->join('pre_sales', function ($join) {
                $join->on('pre_sales.id', '=', 'stock_reservations.source_id')
                    ->whereColumn('pre_sales.business_id', 'stock_reservations.business_id')
                    ->whereColumn('pre_sales.branch_id', 'stock_reservations.branch_id')
                    ->where('stock_reservations.source_type', 'pre_sale');
            })
            ->join('pre_sale_items', function ($join) {
                $join->on('pre_sale_items.id', '=', 'stock_reservations.source_item_id')
                    ->whereColumn('pre_sale_items.pre_sale_id', 'pre_sales.id')
                    ->whereColumn('pre_sale_items.business_id', 'stock_reservations.business_id')
                    ->whereColumn('pre_sale_items.product_id', 'stock_reservations.product_id');
            })
            ->leftJoin('route_visits', function ($join) {
                $join->on('route_visits.id', '=', 'pre_sales.route_visit_id')
                    ->whereColumn('route_visits.business_id', 'pre_sales.business_id')
                    ->whereColumn('route_visits.branch_id', 'pre_sales.branch_id');
            })
            ->where('stock_reservations.business_id', $businessId)
            ->where('stock_reservations.branch_id', $branchId)
            ->whereIn('stock_reservations.product_id', $productIds)
            ->where('stock_reservations.status', 'active')
            ->whereIn('pre_sales.status', ['draft', 'submitted', 'processing', 'picked'])
            ->where(fn ($query) => $query->whereNull('route_visits.id')->orWhere('route_visits.status', '!=', 'without_sale'))
            ->groupBy('stock_reservations.product_id')
            ->selectRaw('stock_reservations.product_id, COALESCE(SUM(stock_reservations.quantity), 0) as reserved')
            ->pluck('reserved', 'product_id')
            ->map(fn ($quantity) => (float) $quantity);

        $reservedCreditReservations = Credits::reserveStockOnCreditReservations($businessId)
            ? CreditReceiptLine::query()
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->whereIn('product_id', $productIds)
                ->whereIn('status', ['pending', 'partially_invoiced'])
                ->where('qty_reserved', '>', 0)
                ->groupBy('product_id')
                ->selectRaw('product_id, COALESCE(SUM(LEAST(qty_reserved, qty_pending)), 0) as reserved')
                ->pluck('reserved', 'product_id')
                ->map(fn ($quantity) => (float) $quantity)
            : collect();

        $reservedOther = StockReservation::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->whereNotIn('source_type', ['pre_sale', 'credit_receipt'])
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as reserved')
            ->pluck('reserved', 'product_id')
            ->map(fn ($quantity) => (float) $quantity);

        return collect($productIds)->mapWithKeys(function (int $productId) use (
            $physicalStock,
            $reservedPreSales,
            $reservedCreditReservations,
            $reservedOther
        ) {
            $physical = (float) ($physicalStock[$productId] ?? 0);
            $preSales = (float) ($reservedPreSales[$productId] ?? 0);
            $creditReservations = (float) ($reservedCreditReservations[$productId] ?? 0);
            $other = (float) ($reservedOther[$productId] ?? 0);
            $reservedTotal = $preSales + $creditReservations + $other;

            return [$productId => [
                'physical_stock' => $physical,
                'reserved_pre_sales' => $preSales,
                'reserved_credit_reservations' => $creditReservations,
                'reserved_other' => $other,
                'reserved_total' => $reservedTotal,
                'available_stock' => $physical - $reservedTotal,
            ]];
        });
    }

    public static function totalStock(Product|int $product, ?int $variantId = null, ?int $branchId = null): float
    {
        $productId = $product instanceof Product ? (int) $product->id : (int) $product;
        $businessId = $product instanceof Product ? (int) $product->business_id : currentBusinessId();
        $branchId ??= BranchInventory::activeBranch($businessId)->id;

        return (float) ProductBranchStock::query()
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->value('stock');
    }

    public static function reservedStock(Product|int $product, ?int $variantId = null, ?int $branchId = null): int|float
    {
        $productId = $product instanceof Product ? (int) $product->id : (int) $product;
        $businessId = $product instanceof Product ? (int) $product->business_id : currentBusinessId();
        $branchId ??= BranchInventory::activeBranch($businessId)->id;

        $reserved = (float) (self::getBreakdownForProducts($businessId, $branchId, [$productId])
            ->get($productId)['reserved_total'] ?? 0);

        return floor($reserved) === $reserved ? (int) $reserved : $reserved;
    }

    public static function availableStock(Product|int $product, ?int $variantId = null, ?int $branchId = null): float
    {
        return self::totalStock($product, $variantId, $branchId) - self::reservedStock($product, $variantId, $branchId);
    }

    public static function explainProductReservations(int $businessId, int $branchId, Product|int $product): array
    {
        $productId = $product instanceof Product ? (int) $product->id : (int) $product;
        $breakdown = self::getBreakdownForProducts($businessId, $branchId, [$productId])->get($productId, [
            'physical_stock' => 0.0,
            'reserved_pre_sales' => 0.0,
            'reserved_credit_reservations' => 0.0,
            'reserved_other' => 0.0,
            'reserved_total' => 0.0,
            'available_stock' => 0.0,
        ]);

        return [
            ...$breakdown,
            'pre_sale_reservations' => self::preSaleReservationRows($businessId, $branchId, $productId),
            'credit_reservations' => self::creditReservationRows($businessId, $branchId, $productId),
            'orphan_or_ignored_reservations' => self::ignoredPreSaleReservationRows($businessId, $branchId, $productId),
        ];
    }

    private static function preSaleReservationRows(int $businessId, int $branchId, int $productId): array
    {
        return DB::table('stock_reservations')
            ->join('pre_sales', function ($join) {
                $join->on('pre_sales.id', '=', 'stock_reservations.source_id')
                    ->whereColumn('pre_sales.business_id', 'stock_reservations.business_id')
                    ->whereColumn('pre_sales.branch_id', 'stock_reservations.branch_id')
                    ->where('stock_reservations.source_type', 'pre_sale');
            })
            ->join('pre_sale_items', function ($join) {
                $join->on('pre_sale_items.id', '=', 'stock_reservations.source_item_id')
                    ->whereColumn('pre_sale_items.pre_sale_id', 'pre_sales.id')
                    ->whereColumn('pre_sale_items.business_id', 'stock_reservations.business_id')
                    ->whereColumn('pre_sale_items.product_id', 'stock_reservations.product_id');
            })
            ->leftJoin('route_visits', function ($join) {
                $join->on('route_visits.id', '=', 'pre_sales.route_visit_id')
                    ->whereColumn('route_visits.business_id', 'pre_sales.business_id')
                    ->whereColumn('route_visits.branch_id', 'pre_sales.branch_id');
            })
            ->leftJoin('customers', function ($join) {
                $join->on('customers.id', '=', 'pre_sales.customer_id')
                    ->whereColumn('customers.business_id', 'pre_sales.business_id');
            })
            ->leftJoin('route_zones', function ($join) {
                $join->on('route_zones.id', '=', 'pre_sales.route_zone_id')
                    ->whereColumn('route_zones.business_id', 'pre_sales.business_id')
                    ->whereColumn('route_zones.branch_id', 'pre_sales.branch_id');
            })
            ->leftJoin('users', function ($join) {
                $join->on('users.id', '=', 'pre_sales.seller_id')
                    ->whereColumn('users.business_id', 'pre_sales.business_id');
            })
            ->where('stock_reservations.business_id', $businessId)
            ->where('stock_reservations.branch_id', $branchId)
            ->where('stock_reservations.product_id', $productId)
            ->where('stock_reservations.status', 'active')
            ->whereIn('pre_sales.status', ['draft', 'submitted', 'processing', 'picked'])
            ->where(fn ($query) => $query->whereNull('route_visits.id')->orWhere('route_visits.status', '!=', 'without_sale'))
            ->orderBy('stock_reservations.created_at')
            ->limit(100)
            ->get([
                'stock_reservations.id as reservation_id',
                'pre_sales.id as pre_sale_id',
                'pre_sales.route_visit_id as visit_id',
                'customers.name as customer_name',
                'customers.commercial_name as customer_commercial_name',
                'route_zones.name as route_zone_name',
                'users.name as seller_name',
                'pre_sales.status as pre_sale_status',
                'route_visits.status as visit_status',
                'stock_reservations.quantity',
                'stock_reservations.created_at',
            ])
            ->map(fn ($row) => [
                'reservation_id' => (int) $row->reservation_id,
                'pre_sale_id' => (int) $row->pre_sale_id,
                'visit_id' => $row->visit_id ? (int) $row->visit_id : null,
                'customer_name' => $row->customer_name,
                'customer_commercial_name' => $row->customer_commercial_name,
                'route_zone_name' => $row->route_zone_name,
                'seller_name' => $row->seller_name,
                'pre_sale_status' => $row->pre_sale_status,
                'visit_status' => $row->visit_status,
                'quantity' => (float) $row->quantity,
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    private static function creditReservationRows(int $businessId, int $branchId, int $productId): array
    {
        if (! Credits::reserveStockOnCreditReservations($businessId)) {
            return [];
        }

        return DB::table('credit_receipt_lines')
            ->join('credit_receipts', function ($join) {
                $join->on('credit_receipts.id', '=', 'credit_receipt_lines.credit_receipt_id')
                    ->whereColumn('credit_receipts.business_id', 'credit_receipt_lines.business_id');
            })
            ->where('credit_receipt_lines.business_id', $businessId)
            ->where('credit_receipt_lines.branch_id', $branchId)
            ->where('credit_receipt_lines.product_id', $productId)
            ->whereIn('credit_receipt_lines.status', ['pending', 'partially_invoiced'])
            ->where('credit_receipt_lines.qty_reserved', '>', 0)
            ->orderBy('credit_receipt_lines.created_at')
            ->limit(100)
            ->get([
                'credit_receipt_lines.id as line_id',
                'credit_receipts.id as credit_receipt_id',
                'credit_receipts.customer_name',
                'credit_receipts.customer_doc_number',
                'credit_receipt_lines.status',
                'credit_receipt_lines.qty_reserved',
                'credit_receipt_lines.qty_pending',
                'credit_receipt_lines.created_at',
            ])
            ->map(fn ($row) => [
                'line_id' => (int) $row->line_id,
                'credit_receipt_id' => (int) $row->credit_receipt_id,
                'customer_name' => $row->customer_name,
                'customer_doc_number' => $row->customer_doc_number,
                'status' => $row->status,
                'quantity' => (float) min((int) $row->qty_reserved, (int) $row->qty_pending),
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    private static function ignoredPreSaleReservationRows(int $businessId, int $branchId, int $productId): array
    {
        return DB::table('stock_reservations')
            ->leftJoin('pre_sales', function ($join) {
                $join->on('pre_sales.id', '=', 'stock_reservations.source_id')
                    ->whereColumn('pre_sales.business_id', 'stock_reservations.business_id')
                    ->whereColumn('pre_sales.branch_id', 'stock_reservations.branch_id')
                    ->where('stock_reservations.source_type', 'pre_sale');
            })
            ->leftJoin('pre_sale_items', function ($join) {
                $join->on('pre_sale_items.id', '=', 'stock_reservations.source_item_id')
                    ->whereColumn('pre_sale_items.business_id', 'stock_reservations.business_id')
                    ->whereColumn('pre_sale_items.product_id', 'stock_reservations.product_id');
            })
            ->leftJoin('route_visits', function ($join) {
                $join->on('route_visits.id', '=', 'pre_sales.route_visit_id')
                    ->whereColumn('route_visits.business_id', 'pre_sales.business_id')
                    ->whereColumn('route_visits.branch_id', 'pre_sales.branch_id');
            })
            ->where('stock_reservations.business_id', $businessId)
            ->where('stock_reservations.branch_id', $branchId)
            ->where('stock_reservations.product_id', $productId)
            ->where('stock_reservations.source_type', 'pre_sale')
            ->where('stock_reservations.status', 'active')
            ->where(function ($query) {
                $query->whereNull('pre_sales.id')
                    ->orWhereNotIn('pre_sales.status', ['draft', 'submitted', 'processing', 'picked'])
                    ->orWhereNull('pre_sale_items.id')
                    ->orWhereColumn('pre_sale_items.pre_sale_id', '!=', 'pre_sales.id')
                    ->orWhere('route_visits.status', 'without_sale');
            })
            ->orderBy('stock_reservations.created_at')
            ->limit(100)
            ->get([
                'stock_reservations.id as reservation_id',
                'stock_reservations.source_id',
                'stock_reservations.source_item_id',
                'stock_reservations.quantity',
                'stock_reservations.created_at',
                'pre_sales.id as pre_sale_id',
                'pre_sales.status as pre_sale_status',
                'pre_sale_items.id as pre_sale_item_id',
                'pre_sale_items.pre_sale_id as item_pre_sale_id',
                'route_visits.id as visit_id',
                'route_visits.status as visit_status',
            ])
            ->map(fn ($row) => [
                'reservation_id' => (int) $row->reservation_id,
                'source_id' => (int) $row->source_id,
                'source_item_id' => $row->source_item_id ? (int) $row->source_item_id : null,
                'pre_sale_id' => $row->pre_sale_id ? (int) $row->pre_sale_id : null,
                'visit_id' => $row->visit_id ? (int) $row->visit_id : null,
                'pre_sale_status' => $row->pre_sale_status,
                'visit_status' => $row->visit_status,
                'quantity' => (float) $row->quantity,
                'created_at' => (string) $row->created_at,
                'reason' => self::ignoredPreSaleReservationReason($row),
            ])
            ->all();
    }

    private static function ignoredPreSaleReservationReason(object $row): string
    {
        if (! $row->pre_sale_id) {
            return 'missing_pre_sale';
        }

        if (! in_array($row->pre_sale_status, ['draft', 'submitted', 'processing', 'picked'], true)) {
            return 'invalid_pre_sale_status';
        }

        if (! $row->pre_sale_item_id) {
            return 'missing_pre_sale_item';
        }

        if ((int) $row->item_pre_sale_id !== (int) $row->pre_sale_id) {
            return 'pre_sale_item_mismatch';
        }

        if ($row->visit_status === 'without_sale') {
            return 'visit_without_sale';
        }

        return 'ignored';
    }
}
