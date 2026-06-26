<?php

namespace App\Support\Inventory;

use App\Models\Branch;
use App\Models\Business;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\StockReservation;
use App\Support\StockAvailability;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StockReservationService
{
    public const SOURCE_PRE_SALE = 'pre_sale';

    public function reservePreSaleItem(PreSaleItem $item): StockReservation
    {
        $preSale = $item->preSale()->firstOrFail();

        return StockReservation::query()->updateOrCreate(
            [
                'business_id' => $item->business_id,
                'source_type' => self::SOURCE_PRE_SALE,
                'source_id' => $preSale->id,
                'source_item_id' => $item->id,
            ],
            [
                'branch_id' => $preSale->branch_id,
                'warehouse_id' => null,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'status' => 'active',
                'created_by' => auth()->id(),
                'released_at' => null,
                'consumed_at' => null,
                'cancelled_at' => null,
            ],
        );
    }

    public function syncPreSaleReservations(PreSale $preSale): void
    {
        $preSale->loadMissing('items');
        $itemIds = $preSale->items->pluck('id')->filter()->values()->all();

        foreach ($preSale->items as $item) {
            $this->reservePreSaleItem($item);
        }

        StockReservation::query()
            ->where('business_id', $preSale->business_id)
            ->where('source_type', self::SOURCE_PRE_SALE)
            ->where('source_id', $preSale->id)
            ->where('status', 'active')
            ->when($itemIds !== [], fn ($query) => $query->whereNotIn('source_item_id', $itemIds))
            ->when($itemIds === [], fn ($query) => $query)
            ->update([
                'status' => 'released',
                'released_at' => now(),
            ]);
    }

    public function releasePreSaleReservations(PreSale $preSale): void
    {
        StockReservation::query()
            ->where('business_id', $preSale->business_id)
            ->where('source_type', self::SOURCE_PRE_SALE)
            ->where('source_id', $preSale->id)
            ->where('status', 'active')
            ->update([
                'status' => 'released',
                'released_at' => now(),
            ]);
    }

    public function activeReservedQuantity(Business|int $business, Branch|int $branch, Product|int $product): float
    {
        $businessId = $business instanceof Business ? (int) $business->id : (int) $business;
        $branchId = $branch instanceof Branch ? (int) $branch->id : (int) $branch;
        $productId = $product instanceof Product ? (int) $product->id : (int) $product;

        return (float) StockReservation::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->sum('quantity');
    }

    public function activeReservedQuantityForProducts(int $businessId, int $branchId, array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return StockReservation::query()
            ->selectRaw('product_id, SUM(quantity) as reserved')
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->pluck('reserved', 'product_id')
            ->map(fn ($quantity) => (float) $quantity);
    }

    public function assertAvailableForReservation(
        Business|int $business,
        Branch|int $branch,
        Product $product,
        int|float $qty,
        ?int $ignorePreSaleItemId = null,
    ): void {
        $businessId = $business instanceof Business ? (int) $business->id : (int) $business;
        $branchId = $branch instanceof Branch ? (int) $branch->id : (int) $branch;

        if (StockPolicy::allowsNegativeStockForBusinessId($businessId)) {
            return;
        }

        $available = StockAvailability::availableStock($product, null, $branchId);

        if ($ignorePreSaleItemId) {
            $available += (float) StockReservation::query()
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->where('source_type', self::SOURCE_PRE_SALE)
                ->where('source_item_id', $ignorePreSaleItemId)
                ->where('status', 'active')
                ->sum('quantity');
        }

        if ($available < (float) $qty) {
            throw ValidationException::withMessages([
                'items' => 'No hay suficiente stock disponible.',
            ]);
        }
    }
}
