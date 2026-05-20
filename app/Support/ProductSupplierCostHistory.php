<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ProductSupplierCostHistory
{
    public static function forProducts(int $businessId, array $productIds): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        $rankedRows = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->whereIn('purchase_items.product_id', $productIds)
            ->where('purchase_items.business_id', $businessId)
            ->where('purchases.business_id', $businessId)
            ->whereNotNull('purchases.supplier_id')
            ->select([
                'purchase_items.product_id',
                'suppliers.id as supplier_id',
                'suppliers.name as supplier_name',
                'suppliers.phone as supplier_phone',
                'suppliers.email as supplier_email',
                'suppliers.address as supplier_address',
                'suppliers.contact_person as supplier_contact_person',
                'purchase_items.unit_cost',
                'purchases.created_at',
                'purchases.id as purchase_id',
                'purchases.purchase_number',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY purchase_items.product_id, purchases.supplier_id ORDER BY purchases.created_at DESC, purchase_items.id DESC) as rn'),
            ]);

        $business = Business::query()->select('id', 'country')->find($businessId);
        $timezone = tenantTimezone($business);

        return DB::query()
            ->fromSub($rankedRows, 'supplier_costs')
            ->where('rn', 1)
            ->orderBy('supplier_name')
            ->get([
                'product_id',
                'supplier_id',
                'supplier_name',
                'supplier_phone',
                'supplier_email',
                'supplier_address',
                'supplier_contact_person',
                'unit_cost',
                'created_at',
                'purchase_id',
                'purchase_number',
            ])
            ->map(function ($row) use ($timezone) {
                $row->created_at_formatted = $row->created_at
                    ? Carbon::parse($row->created_at)->timezone($timezone)->format('d/m/Y')
                    : null;

                return $row;
            })
            ->groupBy('product_id');
    }
}
