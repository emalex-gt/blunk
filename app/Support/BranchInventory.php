<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBranch;
use App\Models\ProductBranchStock;
use App\Models\TenantFelSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BranchInventory
{
    public static function branchesEnabled(?int $businessId = null): bool
    {
        $businessId ??= currentBusinessId();

        return (bool) $businessId
            && module_enabled('branches', $businessId)
            && (bool) tenantSetting('use_branches', false, $businessId);
    }

    public static function pricingScope(?int $businessId = null): string
    {
        $scope = tenantSetting('pricing_scope', 'global', $businessId);

        return $scope === 'branch' ? 'branch' : 'global';
    }

    public static function productsShared(?int $businessId = null): bool
    {
        return (bool) tenantSetting('products_shared_across_branches', true, $businessId);
    }

    public static function defaultBranch(int $businessId): Branch
    {
        $business = Business::query()->find($businessId);

        if ($business) {
            return self::ensureDefaultBranch($business);
        }

        return Branch::query()->firstOrCreate(
            ['business_id' => $businessId, 'code' => 'MAIN'],
            [
                'name' => 'Sucursal Principal',
                'is_active' => true,
                'fel_country' => 'GT',
            ],
        );
    }

    public static function defaultBranchForBusiness(Business $business): Branch
    {
        return self::ensureDefaultBranch($business);
    }

    public static function ensureDefaultBranch(Business $business): Branch
    {
        $branch = Branch::query()
            ->where('business_id', $business->id)
            ->where('code', 'MAIN')
            ->first();

        if (! $branch) {
            $branch = Branch::query()
                ->where('business_id', $business->id)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->first();
        }

        if (! $branch) {
            $branch = Branch::query()->create([
                'business_id' => $business->id,
                'name' => 'Sucursal Principal',
                'code' => 'MAIN',
                'is_active' => true,
                'fel_country' => 'GT',
            ]);
        }

        self::backfillFelDataFromLegacySettings($branch);

        return $branch->refresh();
    }

    public static function felBranchForBusiness(Business $business, $user = null): Branch
    {
        if (! self::branchesEnabled($business->id)) {
            return self::ensureDefaultBranch($business);
        }

        $user ??= auth()->user();
        $branchId = null;

        if ($user && self::canSwitchBranches($user) && session('active_branch_id')) {
            $branchId = (int) session('active_branch_id');
        } elseif ($user?->current_branch_id) {
            $branchId = (int) $user->current_branch_id;
        }

        if ($branchId) {
            $branch = Branch::query()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->find($branchId);

            if ($branch) {
                self::backfillFelDataFromLegacySettings($branch);

                return $branch->refresh();
            }
        }

        return self::ensureDefaultBranch($business);
    }

    public static function activeBranch(int $businessId): Branch
    {
        $user = auth()->user();
        $branchId = null;

        if ($user && self::canSwitchBranches($user) && session('active_branch_id')) {
            $branchId = (int) session('active_branch_id');
        } elseif ($user?->current_branch_id) {
            $branchId = (int) $user->current_branch_id;
        }

        if ($branchId) {
            $branch = Branch::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->find($branchId);

            if ($branch) {
                return $branch;
            }
        }

        $default = self::defaultBranch($businessId);

        if ($user && ! $user->is_super_admin && ! $user->current_branch_id && (int) $user->business_id === $businessId) {
            $user->forceFill(['current_branch_id' => $default->id])->save();
        }

        return $default;
    }

    public static function setActiveBranch(int $businessId, int $branchId): Branch
    {
        $branch = Branch::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->findOrFail($branchId);

        $user = auth()->user();

        if ($user && self::canSwitchBranches($user)) {
            session(['active_branch_id' => $branch->id]);
        } elseif ($user) {
            if ((int) $user->current_branch_id !== (int) $branch->id) {
                throw ValidationException::withMessages([
                    'branch_id' => 'No tienes permiso para cambiar de sucursal.',
                ]);
            }

            session(['active_branch_id' => $branch->id]);
        }

        return $branch;
    }

    public static function canSwitchBranches($user = null): bool
    {
        $user ??= auth()->user();

        return (bool) ($user?->is_super_admin) || Permissions::userHas($user, Permissions::BRANCHES_SWITCH);
    }

    public static function branchOptions(int $businessId): Collection
    {
        self::defaultBranch($businessId);

        return Branch::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    public static function stockMap(int $businessId, array $productIds, int $branchId): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        self::ensureStockRows($businessId, $productIds, $branchId);

        return ProductBranchStock::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->pluck('stock', 'product_id');
    }

    public static function priceMap(int $businessId, array $productIds, int $branchId): Collection
    {
        if ($productIds === [] || self::pricingScope($businessId) !== 'branch') {
            return collect();
        }

        $defaultPriceTypeId = DB::table('price_types')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('id');

        return BranchProductPrice::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->when($defaultPriceTypeId, fn ($query) => $query->where('price_type_id', $defaultPriceTypeId), fn ($query) => $query->whereNull('price_type_id'))
            ->whereIn('product_id', $productIds)
            ->pluck('price', 'product_id');
    }

    public static function applyBranchStockAndPrices(Collection $products, int $businessId, int $branchId): Collection
    {
        $productIds = $products->pluck('id')->all();
        $stockMap = self::stockMap($businessId, $productIds, $branchId);
        $priceMap = self::priceMap($businessId, $productIds, $branchId);

        return $products->each(function (Product $product) use ($stockMap, $priceMap) {
            $product->setAttribute('stock', (float) ($stockMap[$product->id] ?? 0));

            if (array_key_exists($product->id, $priceMap->all())) {
                $product->setAttribute('sale_price', $priceMap[$product->id]);
                $product->setAttribute('branch_price_applied', true);
            } else {
                $product->setAttribute('branch_price_applied', false);
            }
        });
    }

    public static function restrictProductsToBranch($query, int $businessId, int $branchId): void
    {
        if (self::productsShared($businessId)) {
            return;
        }

        $query->whereExists(function ($subquery) use ($businessId, $branchId) {
            $subquery->selectRaw('1')
                ->from('product_branches')
                ->whereColumn('product_branches.product_id', 'products.id')
                ->where('product_branches.business_id', $businessId)
                ->where('product_branches.branch_id', $branchId)
                ->where('product_branches.is_active', true);
        });
    }

    public static function ensureProductInBranch(Product $product, int $branchId): void
    {
        if (self::productsShared((int) $product->business_id)) {
            return;
        }

        $enabled = ProductBranch::query()
            ->where('business_id', $product->business_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->exists();

        if (! $enabled) {
            throw ValidationException::withMessages([
                'items' => "El producto {$product->name} no esta activo en la sucursal seleccionada.",
            ]);
        }
    }

    public static function increase(Product $product, int $branchId, float $quantity): array
    {
        return self::change($product, $branchId, abs($quantity));
    }

    public static function decrease(Product $product, int $branchId, float $quantity): array
    {
        return self::change($product, $branchId, -1 * abs($quantity));
    }

    public static function adjust(Product $product, int $branchId, float $newStock): array
    {
        $row = self::lockedStockRow($product, $branchId);
        $previous = (float) $row->stock;

        if ($newStock < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'El stock no puede quedar negativo.',
            ]);
        }

        $row->update(['stock' => $newStock]);
        self::syncProductStock($product);

        return [$previous, $newStock];
    }

    private static function change(Product $product, int $branchId, float $delta): array
    {
        $row = self::lockedStockRow($product, $branchId);
        $previous = (float) $row->stock;
        $newStock = $previous + $delta;

        if ($newStock < 0) {
            throw ValidationException::withMessages([
                'items' => "Stock insuficiente para {$product->name}. Disponible: {$previous}.",
                'quantity' => 'El stock no puede quedar negativo.',
            ]);
        }

        $row->update(['stock' => $newStock]);
        self::syncProductStock($product);

        return [$previous, $newStock];
    }

    private static function lockedStockRow(Product $product, int $branchId): ProductBranchStock
    {
        return ProductBranchStock::query()->firstOrCreate(
            [
                'business_id' => $product->business_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
            ],
            ['stock' => 0],
        )->newQuery()
            ->where('business_id', $product->business_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private static function ensureStockRows(int $businessId, array $productIds, int $branchId): void
    {
        Product::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $productIds)
            ->get(['id', 'business_id', 'stock'])
            ->each(function (Product $product) use ($branchId) {
                ProductBranchStock::query()->firstOrCreate(
                    [
                        'business_id' => $product->business_id,
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                    ],
                    ['stock' => 0],
                );
            });
    }

    private static function syncProductStock(Product $product): void
    {
        if (! Schema::hasTable('product_branch_stocks')) {
            return;
        }

        $total = ProductBranchStock::query()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->sum('stock');

        DB::table('products')
            ->where('id', $product->id)
            ->update(['stock' => $total, 'updated_at' => now()]);
    }

    private static function backfillFelDataFromLegacySettings(Branch $branch): void
    {
        if (! Schema::hasTable('tenant_fel_settings') || ! Schema::hasColumn('branches', 'fel_establishment_code')) {
            return;
        }

        $settings = TenantFelSetting::query()
            ->where('business_id', $branch->business_id)
            ->first();

        if (! $settings) {
            return;
        }

        $updates = [];
        $mapping = [
            'fel_establishment_code' => 'establishment_code',
            'fel_establishment_name' => 'establishment_name',
            'fel_address' => 'establishment_address',
            'fel_postal_code' => 'establishment_postal_code',
            'fel_municipality' => 'establishment_municipality',
            'fel_department' => 'establishment_department',
            'fel_country' => 'establishment_country',
        ];

        foreach ($mapping as $branchColumn => $settingsColumn) {
            if (! filled($branch->{$branchColumn}) && filled($settings->{$settingsColumn})) {
                $updates[$branchColumn] = $settings->{$settingsColumn};
            }
        }

        if (! filled($branch->fel_country)) {
            $updates['fel_country'] = 'GT';
        }

        if ($updates !== []) {
            $branch->forceFill($updates)->save();
        }
    }
}
