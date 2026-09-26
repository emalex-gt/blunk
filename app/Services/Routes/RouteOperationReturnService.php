<?php

namespace App\Services\Routes;

use App\Models\CashMovement;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteDeliveryCollection;
use App\Models\RouteDeliveryStop;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RouteOperationReturn;
use App\Models\RoutePendingCollectionCase;
use App\Models\RoutePostConversionCollection;
use App\Models\RoutePreSaleCollection;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\SaleStockCancellationService;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteOperationReturnService
{
    public function __construct(private readonly SaleStockCancellationService $stock) {}

    public function completeExternal(RouteExternalDeliveryReconciliationItem $item, array $data, User $actor): IdempotencyResult
    {
        return $this->complete('external', (int) $item->id, (int) $item->business_id, (int) $item->branch_id, $data, $actor);
    }

    public function completeStop(RouteDeliveryStop $stop, array $data, User $actor): IdempotencyResult
    {
        return $this->complete('in_app', (int) $stop->id, (int) $stop->business_id, (int) $stop->branch_id, $data, $actor);
    }

    private function complete(string $origin, int $sourceId, int $businessId, int $branchId, array $data, User $actor): IdempotencyResult
    {
        $permission = $origin === 'external' ? Permissions::ROUTES_EXTERNAL_DELIVERY_RECONCILE_CORRECT : Permissions::ROUTES_DELIVERY_RUNS_CORRECT;
        abort_unless($actor->is_active && (int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId && Permissions::userHas($actor, $permission), 403);
        if (($data['goods_received'] ?? false) !== true) {
            throw ValidationException::withMessages(['goods_received' => 'Debe confirmar la recepción física de todos los productos.']);
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Debe indicar el motivo de la devolución.']);
        }
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'La clave de operación es obligatoria.']);
        }

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_operation_return', $key,
            ['origin' => $origin, 'source_id' => $sourceId, 'reason' => $reason, 'note' => $data['note'] ?? null, 'goods_received' => true],
            fn () => $this->completeLocked($origin, $sourceId, $businessId, $branchId, $reason, $data['note'] ?? null, $key, $actor),
            'route_operation_return',
        );
    }

    private function completeLocked(string $origin, int $sourceId, int $businessId, int $branchId, string $reason, ?string $note, string $key, User $actor): array
    {
        $source = $origin === 'external'
            ? RouteExternalDeliveryReconciliationItem::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($sourceId)->lockForUpdate()->firstOrFail()
            : RouteDeliveryStop::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($sourceId)->lockForUpdate()->firstOrFail();

        $delivered = $origin === 'external'
            ? $source->delivery_status === 'delivered' && $source->reconciled_at !== null && $source->delivery_tracking_snapshot === 'external'
            : $source->status === 'delivered' && $source->completed_at !== null && $source->delivery_tracking_snapshot === 'in_app';
        if (! $delivered) {
            throw ValidationException::withMessages(['delivery' => 'Sólo puede devolverse una operación realmente registrada como entregada.']);
        }

        $entry = RouteDeliveryBatchPreSale::query()->whereKey($source->route_delivery_batch_pre_sale_id)->lockForUpdate()->firstOrFail();
        $batch = $entry->batch;
        if (! $batch || (int) $batch->business_id !== $businessId || (int) $batch->branch_id !== $branchId
            || (int) $entry->sale_id !== (int) $source->sale_id || (int) $entry->pre_sale_id !== (int) $source->pre_sale_id
            || ! in_array($batch->stock_deduction_timing, ['invoice', 'picking'], true)) {
            throw ValidationException::withMessages(['delivery' => 'La entrega y su venta no tienen un origen consistente.']);
        }

        $sale = Sale::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($entry->sale_id)->lockForUpdate()->firstOrFail();
        $preSale = PreSale::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($entry->pre_sale_id)->lockForUpdate()->firstOrFail();
        if ($preSale->status !== PreSale::STATUS_CONVERTED || (int) $preSale->converted_sale_id !== (int) $sale->id
            || $sale->status !== 'completed' || $sale->payment_status !== 'unpaid'
            || bccomp((string) $sale->amount_paid, '0', 2) !== 0 || bccomp((string) $sale->credit_balance, '0', 2) !== 0
            || $sale->is_credit_sale || $sale->due_date !== null) {
            throw ValidationException::withMessages(['sale' => 'La venta no es elegible para devolución sin reembolso.']);
        }

        if (SalePayment::query()->where('sale_id', $sale->id)->exists()
            || RoutePreSaleCollection::query()->where('pre_sale_id', $preSale->id)->exists()
            || RouteDeliveryCollection::query()->where('sale_id', $sale->id)->exists()
            || RoutePostConversionCollection::query()->where('sale_id', $sale->id)->exists()
            || DB::table('customer_account_movements')->where('sale_id', $sale->id)->exists()
            || CashMovement::query()->where('reference_type', 'sale')->where('reference_id', $sale->id)->exists()) {
            throw ValidationException::withMessages(['sale' => 'La venta tiene efectos financieros; requiere la fase de reembolso.']);
        }

        $document = $sale->electronicDocument()->lockForUpdate()->first();
        if ($document || in_array($sale->certification_status, ['certified', 'pending', 'unknown'], true)
            || $sale->fel_certified_at || $sale->fel_uuid || $sale->fel_number) {
            throw ValidationException::withMessages(['fel' => 'La venta tiene un estado fiscal que requiere revisión.']);
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

        $case = RoutePendingCollectionCase::query()->where('sale_id', $sale->id)->lockForUpdate()->first();
        if ($case && ($case->status !== 'open' || (int) $case->route_delivery_batch_pre_sale_id !== (int) $entry->id)) {
            throw ValidationException::withMessages(['case' => 'El pendiente de cobro no coincide con la operación.']);
        }

        if (RouteOperationReturn::query()->where('sale_id', $sale->id)->whereIn('status', ['pending', 'completed'])->exists()) {
            throw ValidationException::withMessages(['return' => 'Esta venta ya tiene una devolución activa.']);
        }

        $now = now()->startOfSecond();
        $return = RouteOperationReturn::query()->create([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'route_delivery_batch_pre_sale_id' => $entry->id, 'sale_id' => $sale->id,
            'route_external_delivery_reconciliation_item_id' => $origin === 'external' ? $source->id : null,
            'route_delivery_stop_id' => $origin === 'in_app' ? $source->id : null,
            'reason' => $reason, 'note' => $note, 'status' => 'pending',
            'goods_received_at' => $now, 'goods_received_by' => $actor->id,
            'idempotency_key' => $key,
        ]);

        $this->stock->restore($sale, $actor, true, ['route_operation_return_id' => $return->id]);
        $sale->update([
            'status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => $actor->id,
            'cancellation_reason' => 'Devolución de operación de ruta #'.$return->id.': '.$reason,
        ]);
        if ($case) {
            $case->update([
                'status' => 'not_applicable', 'not_applicable_at' => $now,
                'not_applicable_by' => $actor->id,
                'not_applicable_reason' => 'Operación devuelta #'.$return->id,
            ]);
        }
        $return->update(['status' => 'completed', 'completed_at' => $now, 'completed_by' => $actor->id]);

        return ['result_id' => $return->id, 'response_payload' => ['return_id' => $return->id, 'sale_id' => $sale->id]];
    }
}
