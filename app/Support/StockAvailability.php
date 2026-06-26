<?php

namespace App\Support;

use App\Models\CreditReceiptLine;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\StockReservation;

class StockAvailability
{
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

        $creditReservations = (float) CreditReceiptLine::query()
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'partially_invoiced'])
            ->sum('qty_pending');

        $genericReservations = (float) StockReservation::query()
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->sum('quantity');

        $reserved = $creditReservations + $genericReservations;

        return floor($reserved) === $reserved ? (int) $reserved : $reserved;
    }

    public static function availableStock(Product|int $product, ?int $variantId = null, ?int $branchId = null): float
    {
        return self::totalStock($product, $variantId, $branchId) - self::reservedStock($product, $variantId, $branchId);
    }
}
