<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\RoutePreparationBatch;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Inventory\StockReservationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePreSalePreparationService
{
    public const TIMING_PICKING = 'picking';
    public const TIMING_INVOICE = 'invoice';

    public function __construct(private readonly StockReservationService $reservations)
    {
    }

    /**
     * Validate a pre-sale inside the caller transaction without changing its state.
     *
     * @return array{pre_sale: PreSale, items: Collection<int, PreSaleItem>, rows: Collection<int, array>, reserved_by_item: Collection<int, float>}
     */
    public function validate(PreSale $preSale, array $rows, string $timing): array
    {
        $timing = $this->normalizeTiming($timing);
        $lockedPreSale = PreSale::query()
            ->where('business_id', $preSale->business_id)
            ->whereKey($preSale->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($lockedPreSale->status, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING], true)) {
            throw ValidationException::withMessages([
                'pre_sale' => $lockedPreSale->status === PreSale::STATUS_PICKED
                    ? 'Esta preventa ya está lista para facturar.'
                    : 'La preventa cambió de estado. Actualiza la página e intenta de nuevo.',
            ]);
        }

        $rows = collect($rows)->keyBy(fn (array $row) => (int) ($row['id'] ?? 0));
        if ($rows->isEmpty() || $rows->has(0)) {
            throw ValidationException::withMessages([
                'items' => 'Debes indicar las cantidades preparadas.',
            ]);
        }

        $items = $lockedPreSale->items()
            ->whereIn('id', $rows->keys()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($items->count() !== $lockedPreSale->items()->count() || $items->count() !== $rows->count()) {
            throw ValidationException::withMessages([
                'pre_sale' => 'La preventa cambió. Actualiza la página e intenta de nuevo.',
            ]);
        }

        $this->reservations->lockStockRowsForReservation(
            (int) $lockedPreSale->business_id,
            (int) $lockedPreSale->branch_id,
            $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
        );

        $reservedByItem = StockReservation::query()
            ->where('business_id', $lockedPreSale->business_id)
            ->where('branch_id', $lockedPreSale->branch_id)
            ->where('source_type', StockReservationService::SOURCE_PRE_SALE)
            ->where('source_id', $lockedPreSale->id)
            ->where('status', 'active')
            ->whereIn('source_item_id', $items->keys()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['source_item_id', 'quantity'])
            ->groupBy('source_item_id')
            ->map(fn (Collection $reservations) => round((float) $reservations->sum('quantity'), 4));

        $preparedQuantity = 0.0;

        foreach ($rows as $itemId => $row) {
            $item = $items->get($itemId);
            $pickedQuantity = round((float) ($row['picked_quantity'] ?? 0), 4);
            $requestedQuantity = round((float) $item->quantity, 4);
            $reservedQuantity = round((float) ($reservedByItem->get($itemId) ?? 0), 4);

            if ($pickedQuantity < 0 || $pickedQuantity > $requestedQuantity || $pickedQuantity > $reservedQuantity) {
                throw ValidationException::withMessages([
                    'items' => 'La cantidad preparada no puede exceder lo solicitado ni lo reservado.',
                ]);
            }

            $preparedQuantity += $pickedQuantity;
        }

        if ($preparedQuantity <= 0) {
            throw ValidationException::withMessages([
                'items' => 'No puedes marcar como listo un pedido sin productos preparados. Cancela la preventa si no se preparará.',
            ]);
        }

        if ($timing === self::TIMING_PICKING) {
            $products = Product::query()
                ->where('business_id', $lockedPreSale->business_id)
                ->where('is_active', true)
                ->whereIn('id', $items->pluck('product_id')->unique()->sort()->values()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $items->pluck('product_id')->unique()->count()) {
                throw ValidationException::withMessages([
                    'pre_sale' => 'Uno de los productos preparados ya no está disponible.',
                ]);
            }

            $products->each(function (Product $product) use ($lockedPreSale) {
                    BranchInventory::ensureProductInBranch($product, (int) $lockedPreSale->branch_id);
                });
        }

        return [
            'pre_sale' => $lockedPreSale,
            'items' => $items,
            'rows' => $rows,
            'reserved_by_item' => $reservedByItem,
        ];
    }

    /**
     * The caller must already be in a transaction.
     *
     * @return array{pre_sale: PreSale, total_items: int, total_amount: float}
     */
    public function prepare(PreSale $preSale, array $rows, User $user, string $timing, ?RoutePreparationBatch $batch = null): array
    {
        $timing = $this->normalizeTiming($timing);
        $validated = $this->validate($preSale, $rows, $timing);
        $lockedPreSale = $validated['pre_sale'];
        $items = $validated['items'];

        foreach ($validated['rows'] as $itemId => $row) {
            /** @var PreSaleItem $item */
            $item = $items->get($itemId);
            $pickedQuantity = round((float) ($row['picked_quantity'] ?? 0), 4);

            $item->update([
                'picked_quantity' => $pickedQuantity,
                'picking_note' => $row['picking_note'] ?? null,
                'stock_deducted_quantity' => $timing === self::TIMING_PICKING ? $pickedQuantity : 0,
            ]);

            $this->reservations->syncPreSaleItemPickedReservation($item, $pickedQuantity);

            if ($timing !== self::TIMING_PICKING || $pickedQuantity <= 0) {
                continue;
            }

            $product = Product::query()
                ->where('business_id', $lockedPreSale->business_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($item->product_id);

            if (! $product) {
                throw ValidationException::withMessages([
                    'pre_sale' => 'Uno de los productos preparados ya no está disponible.',
                ]);
            }

            [$previousStock, $newStock] = BranchInventory::decrease($product, (int) $lockedPreSale->branch_id, $pickedQuantity);

            StockMovement::query()->create([
                'business_id' => $lockedPreSale->business_id,
                'branch_id' => $lockedPreSale->branch_id,
                'product_id' => $product->id,
                'type' => 'pre_sale_picking',
                'quantity' => -1 * $pickedQuantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'note' => 'Preparación de preventa #'.$lockedPreSale->id.($batch ? ' en lote #'.$batch->id : ''),
                'created_by' => $user->id,
            ]);

            StockReservation::query()
                ->where('business_id', $lockedPreSale->business_id)
                ->where('branch_id', $lockedPreSale->branch_id)
                ->where('source_type', StockReservationService::SOURCE_PRE_SALE)
                ->where('source_id', $lockedPreSale->id)
                ->where('source_item_id', $item->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'consumed',
                    'consumed_at' => now(),
                ]);
        }

        $lockedPreSale->update([
            'status' => PreSale::STATUS_PICKED,
            'picked_at' => now(),
            'picked_by' => $user->id,
        ]);

        return [
            'pre_sale' => $lockedPreSale->refresh(),
            'total_items' => (int) $validated['rows']->filter(fn (array $row) => (float) ($row['picked_quantity'] ?? 0) > 0)->count(),
            'total_amount' => round((float) $lockedPreSale->total, 2),
        ];
    }

    /** @return array<int, array{id: int, picked_quantity: float, picking_note: null}> */
    public function fullPreparationRows(PreSale $preSale): array
    {
        return $preSale->items()
            ->orderBy('id')
            ->get(['id', 'quantity'])
            ->map(fn (PreSaleItem $item) => [
                'id' => $item->id,
                'picked_quantity' => (float) $item->quantity,
                'picking_note' => null,
            ])
            ->all();
    }

    public function normalizeTiming(?string $timing): string
    {
        return $timing === self::TIMING_PICKING ? self::TIMING_PICKING : self::TIMING_INVOICE;
    }
}
