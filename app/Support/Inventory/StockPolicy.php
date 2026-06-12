<?php

namespace App\Support\Inventory;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Support\StockAvailability;
use Illuminate\Validation\ValidationException;

class StockPolicy
{
    public static function allowsNegativeStock(Business $business): bool
    {
        return self::allowsNegativeStockForBusinessId((int) $business->id);
    }

    public static function allowsNegativeStockForBusinessId(int $businessId): bool
    {
        return (bool) TenantSetting::query()
            ->where('business_id', $businessId)
            ->value('allow_negative_stock');
    }

    public static function assertCanDecreaseStock(
        Business|int $business,
        Branch|int $branch,
        Product $product,
        mixed $variant,
        int|float $qty,
        string $operation,
    ): void {
        $businessId = $business instanceof Business ? (int) $business->id : (int) $business;
        $branchId = $branch instanceof Branch ? (int) $branch->id : (int) $branch;

        if (self::allowsNegativeStockForBusinessId($businessId)) {
            return;
        }

        $variantId = is_numeric($variant) ? (int) $variant : null;

        if (self::availableStock($product, $variantId, $branchId) < (float) $qty) {
            throw ValidationException::withMessages([
                'items' => self::messageForOperation($operation),
                'quantity' => self::messageForOperation($operation),
            ]);
        }
    }

    public static function availableStock(Product $product, ?int $variantId = null, ?int $branchId = null): float
    {
        return StockAvailability::availableStock($product, $variantId, $branchId);
    }

    private static function messageForOperation(string $operation): string
    {
        return $operation === 'transfer'
            ? 'No hay suficiente stock disponible para trasladar.'
            : 'No hay suficiente stock disponible.';
    }
}
