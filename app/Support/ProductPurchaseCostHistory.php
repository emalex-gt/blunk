<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductPurchaseCostHistory
{
    /**
     * Canonical, business-isolated acquisition-cost observations. Native Blunk
     * purchases are queried virtually; imported history remains documentary.
     */
    public static function normalized(int $businessId): Builder
    {
        $blunk = DB::table('purchase_items')
            ->join('purchases', function ($join) {
                $join->on('purchases.id', '=', 'purchase_items.purchase_id')
                    ->whereColumn('purchases.business_id', 'purchase_items.business_id');
            })
            ->where('purchase_items.business_id', $businessId)
            ->where('purchases.business_id', $businessId)
            ->where(fn ($query) => $query->where('purchases.status', 'completed')->orWhereNull('purchases.status'))
            ->selectRaw("purchase_items.product_id, purchases.supplier_id, purchases.branch_id, purchase_items.quantity::numeric as quantity, purchase_items.unit_cost::numeric as unit_cost, purchases.created_at as acquired_at, purchases.supplier_invoice_number, 'blunk'::varchar as source_system, purchase_items.id::text as source_record_id, purchases.id::bigint as purchase_id, purchases.purchase_number, purchase_items.id::bigint as source_row_id, 2::integer as source_priority");

        $historical = DB::table('historical_product_purchase_costs')
            ->where('business_id', $businessId)
            ->selectRaw("product_id, supplier_id, branch_id, quantity::numeric as quantity, unit_cost::numeric as unit_cost, acquired_at, supplier_invoice_number, source_system, source_record_id, NULL::bigint as purchase_id, NULL::varchar as purchase_number, id::bigint as source_row_id, 1::integer as source_priority");

        return DB::query()->fromSub($blunk->unionAll($historical), 'purchase_cost_history');
    }

    public static function latestBySupplier(int $businessId, array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        $ranked = DB::query()
            ->fromSub(self::normalized($businessId), 'cost_history')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('supplier_id')
            ->selectRaw('cost_history.*, ROW_NUMBER() OVER (PARTITION BY product_id, supplier_id ORDER BY acquired_at DESC, source_priority DESC, source_row_id DESC) as rn');

        return DB::query()
            ->fromSub($ranked, 'ranked_costs')
            ->join('suppliers', function ($join) use ($businessId) {
                $join->on('suppliers.id', '=', 'ranked_costs.supplier_id')
                    ->where('suppliers.business_id', $businessId);
            })
            ->where('rn', 1)
            ->orderBy('suppliers.name')
            ->get([
                'ranked_costs.product_id',
                'ranked_costs.supplier_id',
                'suppliers.name as supplier_name',
                'suppliers.phone as supplier_phone',
                'suppliers.email as supplier_email',
                'suppliers.address as supplier_address',
                'suppliers.contact_person as supplier_contact_person',
                'ranked_costs.unit_cost',
                'ranked_costs.acquired_at',
                'ranked_costs.purchase_id',
                'ranked_costs.purchase_number',
                'ranked_costs.source_system',
                'ranked_costs.source_record_id',
            ])
            ->map(function ($row) use ($businessId) {
                $row->created_at = $row->acquired_at;
                $row->created_at_formatted = Carbon::parse($row->acquired_at)
                    ->timezone(tenantTimezone($businessId))
                    ->format('d/m/Y');

                return $row;
            })
            ->groupBy('product_id');
    }

    public static function reportRows(int $businessId): Builder
    {
        return DB::query()
            ->fromSub(self::normalized($businessId), 'cost_history')
            ->join('products', function ($join) use ($businessId) {
                $join->on('products.id', '=', 'cost_history.product_id')
                    ->where('products.business_id', $businessId);
            })
            ->leftJoin('suppliers', function ($join) use ($businessId) {
                $join->on('suppliers.id', '=', 'cost_history.supplier_id')
                    ->where('suppliers.business_id', $businessId);
            })
            ->leftJoin('branches', function ($join) use ($businessId) {
                $join->on('branches.id', '=', 'cost_history.branch_id')
                    ->where('branches.business_id', $businessId);
            });
    }
}
