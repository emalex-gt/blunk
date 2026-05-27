<?php

namespace App\Support;

use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PriceLists
{
    public const SOURCE_PRICE_LIST = 'price_list';
    public const SOURCE_LAST_CUSTOMER = 'last_customer_price';
    public const SOURCE_MANUAL = 'manual';

    public static function active(int $businessId): Collection
    {
        self::ensureDefault($businessId);

        return PriceType::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'business_id', 'name', 'is_default', 'is_active']);
    }

    public static function default(int $businessId): PriceType
    {
        self::ensureDefault($businessId);

        return PriceType::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->firstOrFail();
    }

    public static function getDefaultPriceType(int $businessId): PriceType
    {
        return self::default($businessId);
    }

    public static function ensureDefaultPriceType(int $businessId): PriceType
    {
        self::ensureDefault($businessId);

        return self::default($businessId);
    }

    public static function setDefault(int $businessId, int $priceTypeId): PriceType
    {
        return DB::transaction(function () use ($businessId, $priceTypeId) {
            $priceType = PriceType::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->findOrFail($priceTypeId);

            PriceType::query()
                ->where('business_id', $businessId)
                ->update(['is_default' => false]);

            $priceType->update([
                'is_default' => true,
                'is_active' => true,
            ]);

            return $priceType->refresh();
        });
    }

    public static function getProductPrice(int $productId, int $priceTypeId): ?float
    {
        $price = ProductPrice::query()
            ->where('product_id', $productId)
            ->where('price_type_id', $priceTypeId)
            ->where('is_active', true)
            ->value('price');

        return $price === null ? null : round((float) $price, 2);
    }

    public static function updateProductPrices(int $priceTypeId, array $prices): void
    {
        $priceType = PriceType::query()->findOrFail($priceTypeId);
        $businessId = (int) $priceType->business_id;

        DB::transaction(function () use ($prices, $priceType, $businessId) {
            foreach ($prices as $row) {
                $productId = (int) ($row['product_id'] ?? 0);

                if ($productId <= 0) {
                    continue;
                }

                $product = Product::query()
                    ->where('business_id', $businessId)
                    ->findOrFail($productId);

                $price = round((float) ($row['price'] ?? 0), 2);

                ProductPrice::query()->updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'product_id' => $product->id,
                        'price_type_id' => $priceType->id,
                    ],
                    [
                        'price' => $price,
                        'is_active' => true,
                    ],
                );
            }
        });
    }

    public static function updatePricesForProduct(Product $product, array $prices): void
    {
        $businessId = (int) $product->business_id;

        DB::transaction(function () use ($product, $prices, $businessId) {
            foreach ($prices as $priceTypeId => $price) {
                $priceType = PriceType::query()
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->find($priceTypeId);

                if (! $priceType || $price === null || $price === '') {
                    continue;
                }

                ProductPrice::query()->updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'product_id' => $product->id,
                        'price_type_id' => $priceType->id,
                    ],
                    [
                        'price' => round((float) $price, 2),
                        'is_active' => true,
                    ],
                );
            }
        });
    }

    public static function ensureDefault(int $businessId): void
    {
        $active = PriceType::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($active->isEmpty()) {
            PriceType::create([
                'business_id' => $businessId,
                'name' => 'General',
                'is_default' => true,
                'is_active' => true,
            ]);

            return;
        }

        $defaults = $active->where('is_default', true);

        if ($active->count() === 1 || $defaults->count() !== 1) {
            $default = $defaults->first() ?: $active->first();

            PriceType::query()
                ->where('business_id', $businessId)
                ->update(['is_default' => false]);

            PriceType::query()->whereKey($default->id)->update([
                'is_default' => true,
                'is_active' => true,
            ]);
        }
    }

    public static function priceForProduct(Product $product, ?int $priceTypeId, ?int $branchId = null): array
    {
        $businessId = (int) $product->business_id;
        $default = self::default($businessId);
        $priceTypeId = $priceTypeId ?: $default->id;
        $usedFallback = false;

        $priceType = PriceType::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->find($priceTypeId);

        if (! $priceType) {
            throw ValidationException::withMessages([
                'items' => 'La lista de precios seleccionada no pertenece a esta empresa.',
            ]);
        }

        $price = null;

        if ($branchId && BranchInventory::pricingScope($businessId) === 'branch') {
            $price = DB::table('branch_product_prices')
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->where('price_type_id', $priceType->id)
                ->where('is_active', true)
                ->value('price');
        }

        if ($price === null) {
            $price = ProductPrice::query()
                ->where('business_id', $businessId)
                ->where('product_id', $product->id)
                ->where('price_type_id', $priceType->id)
                ->where('is_active', true)
                ->value('price');
        }

        if ($price === null && (int) $priceType->id !== (int) $default->id) {
            $usedFallback = true;
            $priceType = $default;
            $price = ProductPrice::query()
                ->where('business_id', $businessId)
                ->where('product_id', $product->id)
                ->where('price_type_id', $default->id)
                ->where('is_active', true)
                ->value('price');
        }

        return [
            'price_type_id' => $priceType->id,
            'price' => round((float) ($price ?? $product->sale_price), 2),
            'used_fallback' => $usedFallback,
        ];
    }

    public static function lastCustomerProductPrice(int $businessId, int $customerId, int $productId): ?SaleItem
    {
        return SaleItem::query()
            ->select('sale_items.*')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.business_id', $businessId)
            ->where('sales.customer_id', $customerId)
            ->where('sale_items.product_id', $productId)
            ->where(fn ($query) => $query->where('sales.status', '!=', 'cancelled')->orWhereNull('sales.status'))
            ->latest('sales.created_at')
            ->latest('sale_items.id')
            ->first();
    }
}
