<?php

namespace App\Services\Fiscal;

use App\Models\Business;
use App\Models\CreditReceiptLine;
use App\Models\CreditReceiptLineInvoice;
use App\Models\ElectronicDocument;
use App\Models\FiscalDocumentIntent;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockReservation;
use App\Services\Fel\FelException;
use App\Services\Fel\FelProviderRejectedException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Fel\Providers\Digifact\DigifactReconciliationService;
use App\Support\AccountsReceivable;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\CashRegister;
use App\Support\Credits;
use App\Support\StockAvailability;
use App\Support\Inventory\StockPolicy;
use App\Support\OperationsLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Durable, provider-neutral fiscal intent orchestration.
 *
 * It stores a frozen operation before any provider call. A Sale is created only
 * by finalize(), after certification is known.
 */
class FiscalDocumentIntentService
{
    public function __construct(
        private readonly DigifactInvoiceService $invoiceService,
        private readonly DigifactReconciliationService $reconciliationService,
    ) {
    }

    public function createForDraftSale(Sale $sale, array $operationPayload, string $idempotencyKey, ?int $userId): FiscalDocumentIntent
    {
        try {
            return DB::transaction(function () use ($sale, $operationPayload, $idempotencyKey, $userId) {
                $sale->loadMissing(['business', 'branch', 'customer', 'items.product']);
                $existing = FiscalDocumentIntent::query()
                    ->where('business_id', $sale->business_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $internalReference = Str::uuid()->toString();
                $snapshot = $this->snapshotSale($sale, $operationPayload);
                $operationFingerprint = $this->operationFingerprint($snapshot);
                $duplicate = $this->pendingDuplicate($sale->business_id, $userId, $operationFingerprint);
                if ($duplicate) {
                    $this->throwPendingDuplicate($duplicate);
                }
                $fiscalPayload = $this->invoiceService->buildIntentPayload($sale, $internalReference);
                $cashSessionId = $operationPayload['cash_register_session_id'] ?? null;
                $creditCustomerId = ! empty($snapshot['sale']['is_credit_sale']) ? ($snapshot['sale']['customer_id'] ?? null) : null;
                $creditHold = $creditCustomerId ? (float) ($snapshot['sale']['total'] ?? 0) : 0;

                $intent = FiscalDocumentIntent::query()->create([
                    'business_id' => $sale->business_id,
                    'branch_id' => $sale->branch_id,
                    'cash_register_session_id' => $cashSessionId,
                    'credit_customer_id' => $creditCustomerId,
                    'credit_hold_amount' => $creditHold,
                    'created_by' => $userId,
                    'country' => $sale->business?->country ?: 'GT',
                    'provider' => 'digifact',
                    'environment' => (string) ($operationPayload['environment'] ?? 'test'),
                    'document_type' => $sale->document_type,
                    'operation_type' => 'sale',
                    'internal_reference' => $internalReference,
                    'idempotency_key' => $idempotencyKey,
                    'operation_fingerprint' => $operationFingerprint,
                    'status' => FiscalDocumentIntent::PENDING,
                    'operation_payload' => $snapshot,
                    'fiscal_payload' => $fiscalPayload,
                    'next_reconciliation_at' => now()->addMinutes(5),
                ]);

                $this->reserveStock($intent);

                OperationsLog::event('fel.intent.created', $intent->business_id, [
                    'branch_id' => $intent->branch_id,
                    'user_id' => $intent->created_by,
                    'intent_id' => $intent->id,
                    'document_type' => $intent->document_type,
                    'operation_type' => $intent->operation_type,
                    'status' => $intent->status,
                    'provider' => $intent->provider,
                    'environment' => $intent->environment,
                ]);

                return $intent;
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            $snapshot = $this->snapshotSale($sale, $operationPayload);
            $duplicate = $this->pendingDuplicate($sale->business_id, $userId, $this->operationFingerprint($snapshot));
            if ($duplicate) {
                $this->throwPendingDuplicate($duplicate);
            }

            throw $exception;
        }
    }

    /**
     * A route pre-sale invoice already owns its stock reservation.  Unlike a
     * POS draft, creating the fiscal intent must not create a second hold or
     * consume anything before the provider has certified the document.
     */
    public function createForRoutePreSaleInvoice(Sale $sale, array $operationPayload, string $idempotencyKey, ?int $userId): FiscalDocumentIntent
    {
        return DB::transaction(function () use ($sale, $operationPayload, $idempotencyKey, $userId) {
            $sale->loadMissing(['business', 'branch', 'customer', 'items.product']);
            $existing = FiscalDocumentIntent::query()
                ->where('business_id', $sale->business_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $reference = Str::uuid()->toString();
            $snapshot = [
                ...$this->snapshotSale($sale, $operationPayload),
                'route_pre_sale' => $operationPayload['route_pre_sale'] ?? [],
            ];

            return FiscalDocumentIntent::query()->create([
                'business_id' => $sale->business_id,
                'branch_id' => $sale->branch_id,
                'cash_register_session_id' => $operationPayload['cash_register_session_id'] ?? null,
                'created_by' => $userId,
                'country' => $sale->business?->country ?: 'GT',
                'provider' => 'digifact',
                'environment' => (string) ($operationPayload['environment'] ?? 'test'),
                'document_type' => 'invoice',
                'operation_type' => 'route_pre_sale_invoice',
                'internal_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'status' => FiscalDocumentIntent::PENDING,
                'operation_payload' => $snapshot,
                'fiscal_payload' => $this->invoiceService->buildIntentPayload($sale, $reference),
                'next_reconciliation_at' => now()->addMinutes(5),
            ]);
        });
    }

    /**
     * Legacy route conversion may already have a legitimate commercial Sale.
     * This intent certifies that exact operation; finalization only attaches
     * FEL evidence and never replays stock, cash, payments or CxC.
     */
    public function createForExistingSale(Sale $sale, string $idempotencyKey, ?int $userId, string $operationType = 'certify_existing_sale'): FiscalDocumentIntent
    {
        return DB::transaction(function () use ($sale, $idempotencyKey, $userId, $operationType) {
            $sale = Sale::query()->with(['business', 'branch', 'customer', 'items.product'])->lockForUpdate()->findOrFail($sale->id);
            $existing = FiscalDocumentIntent::query()
                ->where('business_id', $sale->business_id)
                ->where('finalized_sale_id', $sale->id)
                ->where('operation_type', $operationType)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $reference = $sale->fel_internal_reference ?: Str::uuid()->toString();
            $payload = $this->invoiceService->buildIntentPayload($sale, $reference);

            return FiscalDocumentIntent::query()->create([
                'business_id' => $sale->business_id,
                'branch_id' => $sale->branch_id,
                'finalized_sale_id' => $sale->id,
                'created_by' => $userId,
                'country' => $sale->business?->country ?: 'GT',
                'provider' => 'digifact',
                'environment' => (string) ($sale->electronicDocument?->environment ?: 'test'),
                'document_type' => 'invoice',
                'operation_type' => $operationType,
                'internal_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'status' => FiscalDocumentIntent::PENDING,
                'operation_payload' => ['existing_sale_id' => $sale->id],
                'fiscal_payload' => $payload,
                'next_reconciliation_at' => now()->addMinutes(5),
            ]);
        });
    }

    public function certify(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        // The status vocabulary intentionally has no transient "retrying" state.
        // A short distributed lock is therefore the claim that prevents two queue
        // workers from sending the same pending intent to the provider.
        $lock = Cache::lock("fiscal-document-intent:certify:{$intent->id}", 180);
        if (! $lock->get()) {
            return $intent->refresh();
        }

        try {
            $intent = $this->claimForCertification($intent);

            if ($intent->status === FiscalDocumentIntent::FINALIZED) {
                return $intent;
            }
            if ($intent->status !== FiscalDocumentIntent::PENDING) {
                return $intent;
            }

            OperationsLog::event('fel.certification.started', $intent->business_id, [
                'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id,
                'document_type' => $intent->document_type,
                'operation_type' => $intent->operation_type,
                'status' => $intent->status,
                'provider' => $intent->provider,
                'environment' => $intent->environment,
            ]);

            try {
                $certification = $this->invoiceService->certifyIntent($intent);
            } catch (FelException $exception) {
                return $this->recordCertificationFailure($intent, $exception);
            } catch (\Throwable $exception) {
                return $this->markUnknown($intent, 'certification_exception', $exception->getMessage());
            }

            $intent->update([
                'status' => FiscalDocumentIntent::CERTIFIED,
                'provider_response' => $certification['response_payload'],
                'certified_at' => $certification['identifiers']['certification_date'] ?? now(),
                'last_reconciliation_error' => null,
            ]);

            OperationsLog::event('fel.certification.certified', $intent->business_id, [
                'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id,
                'document_type' => $intent->document_type,
                'operation_type' => $intent->operation_type,
                'status' => FiscalDocumentIntent::CERTIFIED,
                'provider' => $intent->provider,
                'environment' => $intent->environment,
            ]);

            return $this->finalizeSafely($intent->refresh());
        } finally {
            $lock->release();
        }
    }

    public function reconcile(FiscalDocumentIntent $intent, bool $allowRetryable = false): FiscalDocumentIntent
    {
        $intent = DB::transaction(function () use ($intent, $allowRetryable) {
            $locked = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);

            if (! in_array($locked->status, $allowRetryable
                ? [FiscalDocumentIntent::UNKNOWN, FiscalDocumentIntent::RETRYABLE]
                : [FiscalDocumentIntent::UNKNOWN], true)) {
                return $locked;
            }

            return $locked;
        });

        if (! in_array($intent->status, $allowRetryable
            ? [FiscalDocumentIntent::UNKNOWN, FiscalDocumentIntent::RETRYABLE]
            : [FiscalDocumentIntent::UNKNOWN], true)) {
            return $intent;
        }

        try {
            $result = $this->reconciliationService->findByInternalReference(
                $intent->business()->firstOrFail(),
                $this->providerInternalReference($intent),
                $this->frozenIssuedDate($intent),
            );
        } catch (\Throwable $exception) {
            return $this->markUnknown($intent, 'reconciliation_unavailable', $exception->getMessage());
        }

        if (($result['found'] ?? false) === true) {
            if ($this->hasAuthorizationUuidDiscrepancy($intent, (string) ($result['authNumber'] ?? ''))) {
                return $this->markUnknown(
                    $intent,
                    'reconciliation_authorization_mismatch',
                    'Digifact devolvió una autorización distinta de la autorización fiscal local.',
                    $result['raw'] ?? null,
                );
            }

            $intent->update([
                'status' => FiscalDocumentIntent::CERTIFIED,
                'provider_response' => $result['raw'],
                'certified_at' => now(),
                'last_reconciliation_at' => now(),
                'last_reconciliation_result' => ['outcome' => 'found', 'response' => $result],
                'last_reconciliation_error' => null,
            ]);

            return $this->finalizeSafely($intent->refresh());
        }

        return $this->recordValidNegative($intent, $result);
    }

    /** A retry is deliberately a Super Admin action. It always reconciles first. */
    public function retry(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        $intent = DB::transaction(function () use ($intent) {
            $locked = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if ($locked->status !== FiscalDocumentIntent::RETRYABLE) {
                throw new \LogicException('La intención fiscal todavía no está disponible para reintento controlado.');
            }
            return $locked->refresh();
        });

        // A retryable intent must make one last provider query under the
        // controlled retry path. A late certification wins over re-emission.
        $reconciled = $this->reconcile($intent, allowRetryable: true);
        if ($reconciled->status === FiscalDocumentIntent::FINALIZED || $reconciled->status === FiscalDocumentIntent::CERTIFIED) {
            return $reconciled;
        }

        if ($reconciled->status !== FiscalDocumentIntent::RETRYABLE) {
            return $reconciled;
        }

        $reconciled->update(['status' => FiscalDocumentIntent::PENDING]);

        return $this->certify($reconciled->refresh());
    }

    public function finalize(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        return DB::transaction(function () use ($intent) {
            $intent = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if ($intent->status === FiscalDocumentIntent::FINALIZED) {
                return $intent;
            }
            if ($intent->status !== FiscalDocumentIntent::CERTIFIED) {
                throw new \LogicException('Sólo una intención fiscal certificada puede finalizarse.');
            }

            if ($intent->operation_type === 'certify_existing_sale') {
                return $this->finalizeExistingSaleCertification($intent);
            }

            if ($intent->operation_type === 'route_pre_sale_invoice') {
                return app(RoutePreSaleInvoiceFiscalFinalizer::class)->finalize($intent);
            }

            $payload = $intent->operation_payload ?? [];
            $sale = $intent->finalizedSale()
                ->with('items')
                ->lockForUpdate()
                ->first();
            if (! $sale) {
                $sale = $this->createFinalSale($intent, $payload);
                $intent->update(['finalized_sale_id' => $sale->id]);
                $sale->load('items');
            }

            if ($sale->status !== 'completed') {
                $this->applyCommercialEffects($intent, $sale, $payload);
            }

            $this->attachCertificationToSale($intent, $sale);
            $intent->update(['status' => FiscalDocumentIntent::FINALIZED, 'finalized_at' => now(), 'last_finalization_error' => null]);

            OperationsLog::event('fel.finalization.completed', $intent->business_id, [
                'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id,
                'sale_id' => $sale->id,
                'document_type' => $intent->document_type,
                'operation_type' => $intent->operation_type,
                'status' => FiscalDocumentIntent::FINALIZED,
            ]);

            return $intent->refresh();
        });
    }

    /**
     * Completes the commercial side of an ambiguous POS invoice without
     * treating the fiscal result as certified. Reconciliation later attaches
     * the DTE to this same Sale.
     */
    public function acceptProvisionalCommercialFinalization(FiscalDocumentIntent $intent, int $acceptedBy): FiscalDocumentIntent
    {
        return DB::transaction(function () use ($intent, $acceptedBy) {
            $intent = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if ($intent->status === FiscalDocumentIntent::FINALIZED
                && $intent->provisional_accepted_at
                && $intent->finalized_sale_id) {
                return $intent;
            }
            if ($intent->status !== FiscalDocumentIntent::UNKNOWN || $intent->operation_type !== 'sale') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'intent' => 'La constancia provisional sólo puede aceptarse mientras la certificación FEL esté en verificación.',
                ]);
            }

            $payload = $intent->operation_payload ?? [];
            $sale = $intent->finalizedSale()->with('items')->lockForUpdate()->first();
            if (! $sale) {
                $sale = $this->createFinalSale($intent, $payload, 'unknown');
                $intent->update(['finalized_sale_id' => $sale->id]);
                $sale->load('items');
            }

            if ($sale->status !== 'completed') {
                $this->applyCommercialEffects($intent, $sale, $payload);
            }

            if (! $intent->provisional_accepted_at) {
                $intent->update([
                    'provisional_accepted_at' => now(),
                    'provisional_accepted_by' => $acceptedBy,
                ]);
            }

            return $intent->refresh();
        });
    }

    /** Replays local effects only; it never contacts the fiscal provider. */
    public function retryFinalization(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        return $this->finalizeSafely($intent);
    }

    private function finalizeSafely(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        try {
            return $this->finalize($intent);
        } catch (\Throwable $exception) {
            FiscalDocumentIntent::query()->whereKey($intent->id)->update([
                'last_finalization_error' => $exception->getMessage(),
            ]);

            OperationsLog::event('fel.finalization.failed', $intent->business_id, [
                'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id,
                'document_type' => $intent->document_type,
                'operation_type' => $intent->operation_type,
                'status' => $intent->status,
                'reason_code' => 'local_finalization_error',
            ]);

            return $intent->refresh();
        }
    }

    private function finalizeExistingSaleCertification(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        $sale = $intent->finalizedSale()->lockForUpdate()->firstOrFail();
        $ids = $this->invoiceService->extractCertificationIdentifiers($intent->provider_response ?? []);
        $document = $sale->electronicDocument ?: new ElectronicDocument([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'provider' => $intent->provider,
            'environment' => $intent->environment,
            'document_type' => 'invoice',
            'created_by' => $intent->created_by,
        ]);
        $document->fill([
            'internal_reference' => $intent->internal_reference,
            'status' => 'certified',
            'uuid' => $document->uuid ?: ($ids['uuid'] ?? null),
            'series' => $ids['series'] ?? $document->series,
            'number' => $ids['number'] ?? $document->number,
            'certification_date' => $ids['certification_date'] ?? $intent->certified_at,
            'issued_at' => $this->frozenIssuedAt($intent),
            'request_payload' => $intent->fiscal_payload,
            'response_payload' => $intent->provider_response,
            'error_message' => null,
        ])->save();
        $sale->update([
            'electronic_document_id' => $document->id,
            'certification_status' => 'certified',
            'fel_status' => 'CERTIFIED',
            'fel_internal_reference' => $intent->internal_reference,
            'fel_uuid' => $document->uuid,
            'fel_series' => $document->series,
            'fel_number' => $document->number,
            'fel_certified_at' => $document->certification_date,
            'fel_issued_at' => $document->issued_at,
            'fel_raw_response' => $intent->provider_response,
        ]);
        $intent->update(['status' => FiscalDocumentIntent::FINALIZED, 'finalized_at' => now(), 'last_finalization_error' => null]);

        return $intent->refresh();
    }

    private function snapshotSale(Sale $sale, array $operationPayload): array
    {
        $sale->loadMissing('items.product');
        $header = collect($sale->getAttributes())
            ->except(['id', 'created_at', 'updated_at', 'electronic_document_id', 'certification_status', 'fel_uuid', 'fel_series', 'fel_number', 'fel_xml_path', 'fel_html_path', 'fel_pdf_url', 'fel_pdf_path', 'fel_certified_at', 'fel_issued_at', 'fel_status', 'fel_raw_response', 'status'])
            ->all();

        return [
            'sale' => $header,
            'items' => $sale->items->map(fn (SaleItem $item) => collect($item->getAttributes())
                ->except(['id', 'sale_id', 'created_at', 'updated_at'])
                ->all())->values()->all(),
            'payments' => $operationPayload['payments'] ?? [],
            'cash_register_session_id' => $operationPayload['cash_register_session_id'] ?? null,
            // v3 records the consumed reservation quantity by item. credit_line_ids
            // remains only for payloads created before positional associations.
            'credit_line_associations' => $operationPayload['credit_line_associations'] ?? null,
            'credit_line_ids' => $operationPayload['credit_line_ids'] ?? [],
            'draft_id' => $operationPayload['draft_id'] ?? null,
        ];
    }

    private function operationFingerprint(array $snapshot): string
    {
        $payload = Arr::except($snapshot, ['cash_register_session_id', 'draft_id']);
        $payload['sale'] = Arr::except($payload['sale'] ?? [], ['branch_id', 'business_number']);

        return hash('sha256', json_encode($this->sortRecursively($payload), JSON_THROW_ON_ERROR));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function pendingDuplicate(int $businessId, ?int $userId, string $fingerprint): ?FiscalDocumentIntent
    {
        return FiscalDocumentIntent::query()
            ->where('business_id', $businessId)
            ->where('operation_type', 'sale')
            ->when($userId === null, fn ($query) => $query->whereNull('created_by'), fn ($query) => $query->where('created_by', $userId))
            ->where(fn ($query) => $query
                ->where('operation_fingerprint', $fingerprint)
                ->orWhereNull('operation_fingerprint'))
            ->whereIn('status', [FiscalDocumentIntent::PENDING, FiscalDocumentIntent::UNKNOWN, FiscalDocumentIntent::CERTIFIED, FiscalDocumentIntent::RETRYABLE])
            ->lockForUpdate()
            ->get()
            ->first(function (FiscalDocumentIntent $intent) use ($fingerprint): bool {
                $existingFingerprint = $intent->operation_fingerprint;

                if (! $existingFingerprint && is_array($intent->operation_payload)) {
                    $existingFingerprint = $this->operationFingerprint($intent->operation_payload);
                }

                return is_string($existingFingerprint) && hash_equals($existingFingerprint, $fingerprint);
            });
    }

    private function throwPendingDuplicate(FiscalDocumentIntent $intent): never
    {
        throw ValidationException::withMessages([
            'document_type' => 'Ya existe una operación FEL igual en verificación. No se puede emitir otra factura hasta contar con evidencia fiscal de su resolución.',
            'fiscal_intent_id' => (string) $intent->id,
        ]);
    }

    private function reserveStock(FiscalDocumentIntent $intent): void
    {
        $items = $intent->operation_payload['items'] ?? [];
        $association = $intent->operation_payload['credit_line_associations'] ?? null;
        $v3Associations = is_array($association) && ($association['version'] ?? null) === 3
            ? ($association['by_item_index'] ?? null)
            : null;

        foreach ($items as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            if (! $productId || $quantity <= 0) {
                continue;
            }

            $alreadyReservedByCredit = 0.0;
            if (Credits::reserveStockOnCreditReservations($intent->business_id) && is_array($v3Associations)) {
                $entry = $v3Associations[$index] ?? null;
                if ($entry !== null) {
                    if (! is_array($entry)
                        || ! is_numeric($entry['credit_line_id'] ?? null)
                        || (int) $entry['credit_line_id'] <= 0
                        || ! is_numeric($entry['quantity'] ?? null)
                        || (float) $entry['quantity'] < 0
                        || (float) $entry['quantity'] > $quantity
                        || ! is_numeric($entry['stock_reserved_quantity'] ?? null)
                        || (float) $entry['stock_reserved_quantity'] < 0
                        || (float) $entry['stock_reserved_quantity'] > (float) $entry['quantity']) {
                        throw new \RuntimeException('La intención fiscal contiene una asociación de reserva inválida para apartar inventario.');
                    }

                    $alreadyReservedByCredit = (float) $entry['stock_reserved_quantity'];
                }
            }

            $quantityToReserve = $quantity - $alreadyReservedByCredit;
            if ($quantityToReserve <= 0) {
                continue;
            }

            StockReservation::query()->create([
                'business_id' => $intent->business_id,
                'branch_id' => $intent->branch_id,
                'warehouse_id' => null,
                'product_id' => $productId,
                'source_type' => 'fiscal_document_intent',
                'source_id' => $intent->id,
                'source_item_id' => null,
                'quantity' => $quantityToReserve,
                'status' => 'active',
                'created_by' => $intent->created_by,
            ]);
        }
    }

    /**
     * Resolves the reservation association format frozen with a fiscal intent.
     * Version 3 records the exact reserved quantity consumed by each sale item.
     * Version 2 is positional and legacy payloads were keyed by product id.
     *
     * @return array<int, array{credit_line_id: ?int, quantity: int}>
     */
    private function creditLineAssociationsForItems(array $payload, $saleItems): array
    {
        $items = $saleItems->values();
        $association = $payload['credit_line_associations'] ?? null;

        if (is_array($association) && ($association['version'] ?? null) === 3) {
            $byItemIndex = $association['by_item_index'] ?? null;
            if (! is_array($byItemIndex) || count($byItemIndex) !== $items->count()) {
                throw new \RuntimeException('La intención fiscal no contiene asociaciones completas de reservas para finalizarla de forma segura.');
            }

            return $items->keys()->map(function (int $index) use ($byItemIndex, $items): array {
                $entry = $byItemIndex[$index] ?? null;
                if ($entry === null) {
                    return ['credit_line_id' => null, 'quantity' => 0];
                }

                if (! is_array($entry)
                    || ! is_numeric($entry['credit_line_id'] ?? null)
                    || (int) $entry['credit_line_id'] <= 0
                    || ! is_numeric($entry['quantity'] ?? null)
                    || (int) $entry['quantity'] < 0
                    || (int) $entry['quantity'] > (int) $items[$index]->quantity) {
                    throw new \RuntimeException('La intención fiscal contiene una asociación de reserva inválida.');
                }

                return [
                    'credit_line_id' => (int) $entry['credit_line_id'],
                    'quantity' => (int) $entry['quantity'],
                ];
            })->all();
        }

        if (is_array($association) && ($association['version'] ?? null) === 2) {
            $byItemIndex = $association['by_item_index'] ?? null;
            if (! is_array($byItemIndex) || count($byItemIndex) !== $items->count()) {
                throw new \RuntimeException('La intención fiscal no contiene asociaciones completas de reservas para finalizarla de forma segura.');
            }

            return $items->keys()->map(function (int $index) use ($byItemIndex, $items): array {
                if (! array_key_exists($index, $byItemIndex) || blank($byItemIndex[$index])) {
                    return ['credit_line_id' => null, 'quantity' => 0];
                }

                if (! is_numeric($byItemIndex[$index]) || (int) $byItemIndex[$index] <= 0) {
                    throw new \RuntimeException('La intención fiscal contiene una asociación de reserva inválida.');
                }

                return [
                    'credit_line_id' => (int) $byItemIndex[$index],
                    'quantity' => (int) $items[$index]->quantity,
                ];
            })->all();
        }

        $legacy = $payload['credit_line_ids'] ?? [];
        if (! is_array($legacy) || $legacy === []) {
            return array_fill(0, $items->count(), ['credit_line_id' => null, 'quantity' => 0]);
        }

        $itemCountsByProduct = $items->countBy(fn (SaleItem $item) => (int) $item->product_id);

        return $items->map(function (SaleItem $item) use ($legacy, $itemCountsByProduct): array {
            $productId = (int) $item->product_id;
            $legacyId = $legacy[$productId] ?? $legacy[(string) $productId] ?? null;

            if (blank($legacyId)) {
                return ['credit_line_id' => null, 'quantity' => 0];
            }

            if (($itemCountsByProduct[$productId] ?? 0) !== 1) {
                throw new \RuntimeException('La intención fiscal histórica tiene asociaciones de reserva ambiguas y requiere revisión antes de finalizar.');
            }

            if (! is_numeric($legacyId) || (int) $legacyId <= 0) {
                throw new \RuntimeException('La intención fiscal histórica contiene una asociación de reserva inválida.');
            }

            return ['credit_line_id' => (int) $legacyId, 'quantity' => (int) $item->quantity];
        })->values()->all();
    }

    private function consumeStockReservations(FiscalDocumentIntent $intent): void
    {
        StockReservation::query()
            ->where('business_id', $intent->business_id)
            ->where('source_type', 'fiscal_document_intent')
            ->where('source_id', $intent->id)
            ->where('status', 'active')
            ->update(['status' => 'consumed', 'consumed_at' => now()]);
    }

    private function releaseStockReservations(FiscalDocumentIntent $intent): void
    {
        StockReservation::query()
            ->where('business_id', $intent->business_id)
            ->where('source_type', 'fiscal_document_intent')
            ->where('source_id', $intent->id)
            ->where('status', 'active')
            ->update(['status' => 'released', 'released_at' => now()]);
    }

    private function applyCommercialEffects(FiscalDocumentIntent $intent, Sale $sale, array $payload): void
    {
        $isCreditSale = (bool) ($payload['sale']['is_credit_sale'] ?? false);
        $payments = $payload['payments'] ?? [];
        $cashAmount = $isCreditSale ? 0.0 : CashRegister::cashAmountFromPayments($payments);
        $session = $cashAmount > 0 ? $intent->cashRegisterSession()->lockForUpdate()->first() : null;
        if ($cashAmount > 0 && (! $session || $session->status !== 'open')) {
            throw new \RuntimeException('La caja reservada ya no está disponible para finalizar la venta.');
        }
        $business = Business::query()->findOrFail($sale->business_id);
        $saleItems = $sale->items()->orderBy('id')->get()->values();
        $creditLineAssociations = $this->creditLineAssociationsForItems($payload, $saleItems);
        $products = [];
        $creditLines = [];
        $requestedByProduct = [];
        $availableByProduct = [];
        $reservedCreditByProduct = [];
        $requestedByCreditLine = [];

        // Acquire every relevant lock and validate aggregate quantities before any
        // stock, reservation, payment or receipt mutation is written.
        foreach ($saleItems as $itemIndex => $saleItem) {
            $product = Product::query()->where('business_id', $sale->business_id)->whereKey($saleItem->product_id)->lockForUpdate()->firstOrFail();
            $products[$itemIndex] = $product;
            $productId = (int) $product->id;
            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + (int) $saleItem->quantity;
            $availableByProduct[$productId] ??= StockAvailability::availableStock($product, null, $sale->branch_id)
                + (float) StockReservation::query()
                    ->where('business_id', $intent->business_id)
                    ->where('source_type', 'fiscal_document_intent')
                    ->where('source_id', $intent->id)
                    ->where('product_id', $productId)
                    ->where('status', 'active')
                    ->sum('quantity');

            $creditAssociation = $creditLineAssociations[$itemIndex] ?? ['credit_line_id' => null, 'quantity' => 0];
            $creditLineId = $creditAssociation['credit_line_id'];
            $creditQuantity = (int) $creditAssociation['quantity'];
            if (! filled($creditLineId) || $creditQuantity === 0) {
                continue;
            }

            $creditLine = CreditReceiptLine::query()
                ->with('receipt:id,customer_id')
                ->where('business_id', $sale->business_id)
                ->where('branch_id', $sale->branch_id)
                ->where('product_id', $saleItem->product_id)
                ->whereIn('status', ['pending', 'partially_invoiced'])
                ->lockForUpdate()
                ->find($creditLineId);

            $requestedByCreditLine[$creditLineId] = ($requestedByCreditLine[$creditLineId] ?? 0) + $creditQuantity;
            if (! $creditLine || (int) $creditLine->receipt?->customer_id !== (int) $sale->customer_id || $requestedByCreditLine[$creditLineId] > (int) $creditLine->qty_pending) {
                throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'La cantidad acumulada de la reserva ya no está disponible para finalizar la venta.']);
            }

            $creditLines[$itemIndex] = ['line' => $creditLine, 'quantity' => $creditQuantity];
            if (Credits::reserveStockOnCreditReservations($sale->business_id) && (int) $creditLine->qty_reserved > 0) {
                $reservedCreditByProduct[$productId] = ($reservedCreditByProduct[$productId] ?? 0)
                    + min($creditQuantity, (int) $creditLine->qty_reserved);
            }
        }

        if (! StockPolicy::allowsNegativeStock($business)) {
            foreach ($requestedByProduct as $productId => $requestedQuantity) {
                if (($availableByProduct[$productId] ?? 0) + ($reservedCreditByProduct[$productId] ?? 0) < $requestedQuantity) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'No hay suficiente stock disponible para finalizar la venta.']);
                }
            }
        }

        foreach ($saleItems as $itemIndex => $saleItem) {
            $product = $products[$itemIndex];
            $creditAssociation = $creditLines[$itemIndex] ?? null;

            [$previousStock, $newStock] = BranchInventory::decrease($product, $sale->branch_id, $saleItem->quantity);
            \App\Models\StockMovement::query()->create([
                'business_id' => $sale->business_id,
                'branch_id' => $sale->branch_id,
                'product_id' => $product->id,
                'type' => 'sale',
                'quantity' => -1 * $saleItem->quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'note' => stockMovementNote('sale', $sale->business_number ?: $sale->id),
                'created_by' => $sale->created_by,
            ]);

            if ($creditAssociation) {
                /** @var CreditReceiptLine $creditLine */
                $creditLine = $creditAssociation['line'];
                $creditQuantity = (int) $creditAssociation['quantity'];
                $creditLine->increment('qty_invoiced', $creditQuantity);
                $creditLine = Credits::refreshLine($creditLine->refresh());
                Credits::refreshReceipt($creditLine->receipt);
                CreditReceiptLineInvoice::query()->create([
                    'business_id' => $sale->business_id,
                    'credit_receipt_line_id' => $creditLine->id,
                    'sale_id' => $sale->id,
                    'sale_line_id' => $saleItem->id,
                    'quantity' => $creditQuantity,
                    'amount' => round((float) $saleItem->total * ($creditQuantity / (int) $saleItem->quantity), 2),
                ]);
            }
        }

        foreach ($payments as $payment) {
            $sale->payments()->create([
                'business_id' => $sale->business_id,
                'method' => $payment['method'],
                'amount' => round((float) $payment['amount'], 2),
                'reference' => $payment['reference'] ?? null,
                'details' => $payment['details'] ?? null,
                'cash_register_session_id' => $intent->cash_register_session_id,
            ]);
        }

        if ($cashAmount > 0 && $session) {
            CashRegister::recordMovement($session, 'sale_cash', $cashAmount, 'sale', $sale->id, stockMovementNote('sale', $sale->business_number ?: $sale->id), $sale->created_by);
        }
        if ($isCreditSale) {
            $intent->update(['credit_hold_amount' => 0]);
            AccountsReceivable::createCharge($sale, $sale->created_by);
        }

        $sale->update([
            'status' => 'completed',
            'payment_status' => $isCreditSale ? 'unpaid' : 'paid',
            'amount_paid' => $isCreditSale ? 0 : $sale->total,
            'credit_balance' => $isCreditSale ? $sale->total : 0,
            'is_credit_sale' => $isCreditSale,
            'due_date' => $isCreditSale ? ($payload['sale']['due_date'] ?? null) : null,
        ]);

        $this->markQuotationConverted($sale);
        $this->consumeStockReservations($intent);
    }

    private function createFinalSale(FiscalDocumentIntent $intent, array $payload, string $certificationStatus = 'certified'): Sale
    {
        $header = $payload['sale'] ?? [];
        if (! is_array($header) || $header === []) {
            throw new \LogicException('La intención fiscal no contiene una operación de venta finalizable.');
        }

        $sale = Sale::query()->create([
            ...$header,
            'business_id' => $intent->business_id,
            'branch_id' => $intent->branch_id,
            'business_number' => $header['business_number'] ?? BusinessCounter::next($intent->business_id, 'sales'),
            'status' => 'fiscal_pending',
            'payment_status' => 'pending',
            'amount_paid' => 0,
            'credit_balance' => 0,
            'fel_internal_reference' => $intent->internal_reference,
            'certification_status' => $certificationStatus,
            'fel_status' => strtoupper($certificationStatus),
        ]);

        foreach (($payload['items'] ?? []) as $item) {
            $sale->items()->create([
                ...$item,
                'business_id' => $intent->business_id,
            ]);
        }

        return $sale->refresh();
    }

    private function attachCertificationToSale(FiscalDocumentIntent $intent, Sale $sale): void
    {
        $ids = $this->invoiceService->extractCertificationIdentifiers($intent->provider_response ?? []);
        $document = ElectronicDocument::query()
            ->where('business_id', $sale->business_id)
            ->where('sale_id', $sale->id)
            ->lockForUpdate()
            ->first() ?: new ElectronicDocument([
                'business_id' => $sale->business_id,
                'sale_id' => $sale->id,
                'provider' => $intent->provider,
                'environment' => $intent->environment,
                'document_type' => $intent->document_type,
                'created_by' => $intent->created_by,
            ]);

        $document->fill([
            'internal_reference' => $intent->internal_reference,
            'status' => 'certified',
            'uuid' => $ids['uuid'] ?? $document->uuid,
            'series' => $ids['series'] ?? $document->series,
            'number' => $ids['number'] ?? $document->number,
            'certification_date' => $ids['certification_date'] ?? $intent->certified_at,
            'issued_at' => $this->frozenIssuedAt($intent),
            'request_payload' => $intent->fiscal_payload,
            'response_payload' => $intent->provider_response,
            'error_message' => null,
        ])->save();

        $sale->update([
            'electronic_document_id' => $document->id,
            'certification_status' => 'certified',
            'fel_status' => 'CERTIFIED',
            'fel_internal_reference' => $intent->internal_reference,
            'fel_uuid' => $document->uuid,
            'fel_series' => $document->series,
            'fel_number' => $document->number,
            'fel_certified_at' => $document->certification_date,
            'fel_issued_at' => $document->issued_at,
            'fel_raw_response' => $intent->provider_response,
        ]);
    }

    private function markQuotationConverted(Sale $sale): void
    {
        if (! $sale->quotation_id) {
            return;
        }

        $quotation = Quotation::query()
            ->where('business_id', $sale->business_id)
            ->lockForUpdate()
            ->findOrFail($sale->quotation_id);

        if ($quotation->status === Quotation::CONVERTED) {
            if ($quotation->convertedSale()->whereKey($sale->id)->exists()) {
                return;
            }
            throw new \LogicException('La cotización ya fue utilizada por otra venta.');
        }
        if ($quotation->status !== Quotation::OPEN) {
            throw new \LogicException('La cotización ya no puede finalizarse como venta.');
        }

        $quotation->update(['status' => Quotation::CONVERTED, 'converted_at' => now()]);
    }

    private function claimForCertification(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        return DB::transaction(function () use ($intent) {
            $locked = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if ($locked->status === FiscalDocumentIntent::FINALIZED) {
                return $locked;
            }
            if ($locked->status !== FiscalDocumentIntent::PENDING) {
                return $locked;
            }
            return $locked;
        });
    }

    private function providerInternalReference(FiscalDocumentIntent $intent): string
    {
        $reference = data_get($intent->fiscal_payload, 'AdditionalDocumentInfo.AdditionalInfo.0.Code');

        return is_string($reference) && $reference !== ''
            ? $reference
            : $intent->internal_reference;
    }

    private function frozenIssuedDate(FiscalDocumentIntent $intent): Carbon|string
    {
        $issuedDate = data_get($intent->fiscal_payload, 'Header.IssuedDateTime');

        return is_string($issuedDate) && $issuedDate !== ''
            ? $issuedDate
            : $intent->created_at;
    }

    private function frozenIssuedAt(FiscalDocumentIntent $intent): Carbon
    {
        $issuedDate = $this->frozenIssuedDate($intent);

        return ($issuedDate instanceof Carbon
            ? $issuedDate->copy()
            : Carbon::parse($issuedDate))
            ->utc();
    }

    private function recordCertificationFailure(FiscalDocumentIntent $intent, FelException $exception): FiscalDocumentIntent
    {
        // A provider business rejection includes a structured provider response. All
        // transport/protocol/auth ambiguity remains unknown and is reconciled first.
        if ($exception instanceof FelProviderRejectedException) {
            $intent->update([
                'status' => FiscalDocumentIntent::REJECTED,
                'provider_response' => $exception->responsePayload(),
                'last_reconciliation_error' => $exception->getMessage(),
            ]);
            $this->releaseStockReservations($intent);

            OperationsLog::event('fel.certification.rejected', $intent->business_id, [
                'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id,
                'document_type' => $intent->document_type,
                'operation_type' => $intent->operation_type,
                'status' => FiscalDocumentIntent::REJECTED,
                'reason_code' => 'provider_rejected',
                'provider' => $intent->provider,
            ]);
            return $intent->refresh();
        }

        return $this->markUnknown($intent, 'certification_ambiguous', $exception->getMessage(), $exception->responsePayload());
    }

    private function markUnknown(FiscalDocumentIntent $intent, string $reason, ?string $error, ?array $snapshot = null): FiscalDocumentIntent
    {
        return DB::transaction(function () use ($intent, $reason, $error, $snapshot) {
            $intent = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $intent->update([
                'status' => FiscalDocumentIntent::UNKNOWN,
                'next_reconciliation_at' => $this->nextReconciliationAt($intent, $intent->negative_reconciliation_count),
                'last_reconciliation_error' => $error,
                'last_reconciliation_result' => ['outcome' => $reason, 'at' => now()->toIso8601String()],
                'provider_response' => $snapshot ?: $intent->provider_response,
            ]);

            OperationsLog::event('fel.certification.unknown', $intent->business_id, [
                'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id,
                'document_type' => $intent->document_type,
                'operation_type' => $intent->operation_type,
                'status' => FiscalDocumentIntent::UNKNOWN,
                'reason_code' => $reason,
                'provider' => $intent->provider,
            ]);

            return $intent->refresh();
        });
    }

    private function hasAuthorizationUuidDiscrepancy(FiscalDocumentIntent $intent, string $providerUuid): bool
    {
        if ($intent->operation_type !== 'certify_existing_sale') {
            return false;
        }

        $sale = $intent->finalizedSale()->with('electronicDocument')->first();
        $persistedUuid = trim((string) ($sale?->electronicDocument?->uuid ?: $sale?->fel_uuid));
        $providerUuid = trim($providerUuid);

        if ($persistedUuid === '' || $providerUuid === '') {
            return false;
        }

        return ! $this->authorizationUuidsMatch($persistedUuid, $providerUuid);
    }

    private function authorizationUuidsMatch(string $persistedUuid, string $providerUuid): bool
    {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        return preg_match($uuidPattern, $persistedUuid) === 1
            && preg_match($uuidPattern, $providerUuid) === 1
            && hash_equals(strtoupper($persistedUuid), strtoupper($providerUuid));
    }

    private function recordValidNegative(FiscalDocumentIntent $intent, array $result): FiscalDocumentIntent
    {
        return DB::transaction(function () use ($intent, $result) {
            $intent = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $count = $intent->negative_reconciliation_count + 1;
            $next = $this->nextReconciliationAt($intent, $count);
            $intent->update([
                'status' => $count >= 3 ? FiscalDocumentIntent::RETRYABLE : FiscalDocumentIntent::UNKNOWN,
                'negative_reconciliation_count' => $count,
                'last_reconciliation_at' => now(),
                'next_reconciliation_at' => $next,
                'last_reconciliation_result' => ['outcome' => 'not_found', 'response' => $result],
                'last_reconciliation_error' => null,
            ]);

            OperationsLog::event('fel.reconciliation.not_found', $intent->business_id, [
                'branch_id' => $intent->branch_id,
                'intent_id' => $intent->id,
                'document_type' => $intent->document_type,
                'operation_type' => $intent->operation_type,
                'status' => $count >= 3 ? FiscalDocumentIntent::RETRYABLE : FiscalDocumentIntent::UNKNOWN,
                'attempt' => $count,
                'reason_code' => 'provider_not_found',
            ]);

            return $intent->refresh();
        });
    }

    private function nextReconciliationAt(FiscalDocumentIntent $intent, int $negativeCount): ?Carbon
    {
        $scheduled = match ($negativeCount) {
            0 => $intent->created_at->copy()->addMinutes(5),
            1 => $intent->created_at->copy()->addMinutes(30),
            2 => $intent->created_at->copy()->addHours(2),
            default => null,
        };

        if ($scheduled === null) {
            return null;
        }

        return $scheduled->isFuture() ? $scheduled : now()->addMinutes(5);
    }
}
