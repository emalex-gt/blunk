<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Fuel configurations link existing Blunk products; there is no second product catalog. */
final class FuelCatalog
{
    public static function available(int $businessId, int $branchId): array
    {
        $configs = DB::table('fuel_product_configs')
            ->where('business_id', $businessId)->where('branch_id', $branchId)
            ->where('is_active', true)->orderBy('id')->get();

        if ($configs->isEmpty()) return [];

        $products = Product::query()->where('business_id', $businessId)->where('is_active', true)
            ->whereIn('id', $configs->pluck('product_id')->all());
        BranchInventory::restrictProductsToBranch($products, $businessId, $branchId);
        $byId = $products->get()->keyBy('id');

        $rows = [];
        foreach ($configs as $config) {
            $product = $byId->get($config->product_id);
            if (! $product) continue;
            try {
                $rows[] = self::present($config, $product, $businessId, $branchId);
            } catch (ValidationException) {
                // A mispriced fuel product must not block the entire station's bootstrap.
                // It will remain unavailable until its Blunk price is corrected.
            }
        }

        return $rows;
    }

    public static function requireConfigured(int $businessId, int $branchId, int $configId, bool $lock = false): array
    {
        $query = DB::table('fuel_product_configs')
            ->where('id', $configId)->where('business_id', $businessId)
            ->where('branch_id', $branchId)->where('is_active', true);
        $config = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $config) throw ValidationException::withMessages(['fuel_product_id' => 'Combustible no configurado en esta sucursal.']);

        $productQuery = Product::query()->where('business_id', $businessId)->where('is_active', true)
            ->whereKey($config->product_id);
        BranchInventory::restrictProductsToBranch($productQuery, $businessId, $branchId);
        $product = $productQuery->first();
        if (! $product) throw ValidationException::withMessages(['fuel_product_id' => 'Producto no disponible en esta sucursal.']);
        return [$config, $product, self::present($config, $product, $businessId, $branchId)];
    }

    private static function present(object $config, Product $product, int $businessId, int $branchId): array
    {
        // Default price list is the source of truth, including branch overrides.
        $defaultPriceType = DB::table('price_types')->where('business_id', $businessId)
            ->where('is_active', true)->where('is_default', true)->value('id');
        $price = $defaultPriceType
            ? PriceLists::getProductPrice((int) $product->id, (int) $defaultPriceType, $businessId, $branchId)
            : null;
        $price ??= (float) $product->sale_price;
        if (! is_finite((float) $price) || $price <= 0 || $price > 999999.99) {
            throw ValidationException::withMessages(['fuel_product_id' => 'El producto combustible no tiene un precio por litro válido.']);
        }

        return [
            'id' => (int) $config->id,
            'product_id' => (int) $product->id,
            'code' => $product->code,
            'label' => $config->label ?: $product->name,
            'unit' => 'L',
            'price_per_liter' => number_format((float) $price, 2, '.', ''),
            'currency' => 'GTQ',
        ];
    }
}
