<?php

namespace App\Services\Routes;

use App\Models\CashMovement;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteDeliveryCollection;
use App\Models\RoutePostConversionCollection;
use App\Models\RoutePreSaleCollection;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\SaleStockCancellationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteUnpaidSaleCancellationService
{
    public function __construct(private readonly SaleStockCancellationService $stock) {}

    /** The reconciliation caller owns the transaction and has locked batch and entry. */
    public function cancel(RouteDeliveryBatchPreSale $entry, User $actor, string $reason): Sale
    {
        $batch = $entry->batch;
        if ($batch?->delivery_tracking_snapshot !== 'external'
            || $batch->collection_workflow_mode_snapshot !== 'per_order_collection'
            || $batch->collection_responsibility_snapshot !== 'delivery_agent'
            || ! in_array($batch->stock_deduction_timing, ['invoice', 'picking'], true)) {
            throw ValidationException::withMessages(['reconciliation' => 'Esta operación requiere una anulación administrativa especializada.']);
        }

        $sale = Sale::query()->where('business_id', $batch->business_id)->where('branch_id', $batch->branch_id)
            ->whereKey($entry->sale_id)->lockForUpdate()->firstOrFail();
        $preSale = PreSale::query()->where('business_id', $batch->business_id)->where('branch_id', $batch->branch_id)
            ->whereKey($entry->pre_sale_id)->lockForUpdate()->firstOrFail();

        if ($preSale->status !== PreSale::STATUS_CONVERTED || (int) $preSale->converted_sale_id !== (int) $sale->id
            || $sale->status !== 'completed' || $sale->payment_status !== 'unpaid'
            || bccomp((string) $sale->amount_paid, '0', 2) !== 0
            || bccomp((string) $sale->credit_balance, '0', 2) !== 0
            || $sale->is_credit_sale || $sale->due_date !== null) {
            throw ValidationException::withMessages(['sale' => 'La venta no es elegible para anulación automática.']);
        }

        if (SalePayment::query()->where('sale_id', $sale->id)->exists()
            || RoutePreSaleCollection::query()->where('pre_sale_id', $preSale->id)->exists()
            || RouteDeliveryCollection::query()->where('sale_id', $sale->id)->exists()
            || RoutePostConversionCollection::query()->where('sale_id', $sale->id)->exists()
            || DB::table('customer_account_movements')->where('sale_id', $sale->id)->exists()
            || CashMovement::query()->where('reference_type', 'sale')->where('reference_id', $sale->id)->exists()) {
            throw ValidationException::withMessages(['sale' => 'La venta conserva efectos financieros y requiere una reversa administrativa.']);
        }

        $document = $sale->electronicDocument()->lockForUpdate()->first();
        if ($document || in_array($sale->certification_status, ['certified', 'pending', 'unknown'], true)
            || $sale->fel_certified_at || $sale->fel_uuid || $sale->fel_number) {
            throw ValidationException::withMessages(['fel' => 'La venta tiene un estado fiscal que requiere revisión antes de anularla.']);
        }

        if ($batch->stock_deduction_timing === 'picking') {
            $picked = $preSale->items()->get()->groupBy('product_id')->map(fn ($items) => $items->sum('stock_deducted_quantity'));
            $sold = $sale->items()->get()->groupBy('product_id')->map(fn ($items) => $items->sum('quantity'));
            if ($picked->keys()->sort()->values()->all() !== $sold->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['stock' => 'El descuento histórico de preparación no coincide con la venta.']);
            }
            foreach ($sold as $productId => $quantity) {
                if (abs((float) $picked->get($productId) - (float) $quantity) > 0.0001) {
                    throw ValidationException::withMessages(['stock' => 'El descuento histórico de preparación no coincide con la venta.']);
                }
            }
        }

        $this->stock->restore($sale, $actor, true);
        $sale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancellation_reason' => 'No entregado: '.$reason,
        ]);

        return $sale;
    }
}
