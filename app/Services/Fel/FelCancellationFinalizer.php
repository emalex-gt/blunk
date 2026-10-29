<?php

namespace App\Services\Fel;

use App\Models\ElectronicDocument;
use App\Models\FelCancellationRequest;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleStockCancellationService;
use App\Support\AccountsReceivable;
use App\Support\BranchInventory;
use App\Support\CashRegister;
use Illuminate\Support\Facades\DB;

/**
 * Applies local effects only after a separately durable fiscal cancellation
 * has been confirmed. This service never contacts a provider.
 */
class FelCancellationFinalizer
{
    public function __construct(private readonly SaleStockCancellationService $stockCancellation)
    {
    }

    public function finalize(FelCancellationRequest $request, User $actor, int $businessId): Sale
    {
        try {
            return DB::transaction(function () use ($request, $actor, $businessId) {
                $lockedRequest = FelCancellationRequest::query()
                    ->with('electronicDocument')
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->findOrFail($request->id);

                if ($lockedRequest->status !== FelCancellationRequest::CONFIRMED) {
                    throw new FelException('La anulación fiscal no está confirmada para finalizar localmente.');
                }

                $document = ElectronicDocument::query()
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->findOrFail($lockedRequest->electronic_document_id);

                $sale = Sale::query()
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->with(['business', 'items', 'payments', 'electronicDocument'])
                    ->findOrFail($lockedRequest->sale_id);

                $this->assertFiscalIdentity($lockedRequest, $document, $sale);

                if ($lockedRequest->finalized_at) {
                    return $sale;
                }

                if (($sale->status ?? 'completed') === 'cancelled') {
                    throw new FelException('La venta ya fue anulada sin una finalización local registrada para esta solicitud fiscal.');
                }

                if ($sale->is_credit_sale && (float) $sale->amount_paid > 0) {
                    throw new FelException('No se puede anular una venta al crédito con abonos registrados.');
                }

                $branchId = (int) ($sale->branch_id ?: BranchInventory::defaultBranch($businessId)->id);
                $cashAmount = CashRegister::cashAmountFromPayments($sale->payments);

                if ($cashAmount > 0) {
                    CashRegister::requireOpenSession(
                        $businessId,
                        'Debes abrir caja antes de finalizar una anulación de venta con pago en efectivo.',
                        true,
                        $branchId,
                    );
                }

                AccountsReceivable::cancelSaleCharge($sale, $actor);
                $this->stockCancellation->restore($sale, $actor);

                if ($cashAmount > 0) {
                    $cashSession = CashRegister::requireOpenSession(
                        $businessId,
                        'Debes abrir caja antes de finalizar una anulación de venta con pago en efectivo.',
                        true,
                        $branchId,
                    );

                    CashRegister::recordMovement(
                        $cashSession,
                        'sale_cash_cancel',
                        -1 * $cashAmount,
                        'sale',
                        $sale->id,
                        stockMovementNote('sale_cancel', $sale->business_number ?: $sale->id),
                        $actor->id,
                    );
                }

                $sale->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                    'cancellation_reason' => $lockedRequest->reason,
                ]);

                $lockedRequest->update([
                    'finalized_at' => now(),
                    'finalized_by' => $actor->id,
                    'finalization_error' => null,
                ]);

                return $sale->refresh();
            });
        } catch (\Throwable $exception) {
            $this->recordFinalizationFailure($request, $businessId, $exception);

            throw $exception;
        }
    }

    private function assertFiscalIdentity(FelCancellationRequest $request, ElectronicDocument $document, Sale $sale): void
    {
        $matches = (int) $request->business_id === (int) $document->business_id
            && (int) $request->business_id === (int) $sale->business_id
            && (int) $request->sale_id === (int) $document->sale_id
            && (int) $request->electronic_document_id === (int) $document->id
            && hash_equals(strtoupper((string) $request->uuid), strtoupper((string) $document->uuid))
            && $request->provider === $document->provider
            && $request->environment === $document->environment;

        if (! $matches) {
            throw new FelException('La solicitud de anulación no coincide con la evidencia fiscal de la venta.');
        }
    }

    private function recordFinalizationFailure(FelCancellationRequest $request, int $businessId, \Throwable $exception): void
    {
        DB::transaction(function () use ($request, $businessId, $exception) {
            $locked = FelCancellationRequest::query()
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->find($request->id);

            if ($locked && $locked->status === FelCancellationRequest::CONFIRMED && ! $locked->finalized_at) {
                $locked->update([
                    'finalization_error' => $exception->getMessage()
                        ?: 'No se pudo finalizar localmente la anulación fiscal.',
                ]);
            }
        });
    }
}
