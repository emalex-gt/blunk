<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\BranchInventory;
use Illuminate\Validation\ValidationException;

class SaleStockCancellationService
{
    /** The caller owns the transaction and holds the Sale lock. */
    public function restore(Sale $sale, User $actor, bool $requireCompleteReturn = false): void
    {
        $businessId = (int) $sale->business_id;
        $branchId = (int) ($sale->branch_id ?: BranchInventory::defaultBranch($businessId)->id);
        $note = stockMovementNote('sale_cancel', $sale->business_number ?: $sale->id);

        if ($requireCompleteReturn && StockMovement::query()
            ->where('business_id', $businessId)->where('branch_id', $branchId)
            ->where('type', 'sale_cancel')->where('note', $note)->exists()) {
            throw ValidationException::withMessages(['sale' => 'Esta venta ya tiene una devolución de inventario registrada.']);
        }

        foreach ($sale->items()->orderBy('id')->lockForUpdate()->get() as $item) {
            $product = Product::query()
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->find($item->product_id);

            if (! $product) {
                if ($requireCompleteReturn) {
                    throw ValidationException::withMessages(['sale' => 'No se puede devolver el inventario: falta un producto de la venta.']);
                }
                continue;
            }

            [$previousStock, $newStock] = BranchInventory::increase($product, $branchId, (float) $item->quantity);

            StockMovement::create([
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'type' => 'sale_cancel',
                'quantity' => (float) $item->quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'note' => $note,
                'created_by' => $actor->id,
            ]);
        }
    }
}
