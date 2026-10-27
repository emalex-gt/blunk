<?php

namespace App\Support;

use Illuminate\Support\Collection;

class ProductSupplierCostHistory
{
    public static function forProducts(int $businessId, array $productIds): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        return ProductPurchaseCostHistory::latestBySupplier($businessId, $productIds);
    }
}
