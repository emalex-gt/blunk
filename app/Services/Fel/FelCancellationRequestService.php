<?php

namespace App\Services\Fel;

use App\Models\ElectronicDocument;
use App\Models\FelCancellationRequest;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists a cancellation lifecycle before the provider is contacted.
 *
 * This service deliberately does not reverse a sale's commercial effects.
 * P0.3 will consume a CONFIRMED request and perform that local work in its
 * own idempotent transaction.
 */
class FelCancellationRequestService
{
    public function __construct(private readonly DigifactInvoiceService $invoiceService)
    {
    }

    public function request(ElectronicDocument $document, string $reason, ?int $requestedBy = null): FelCancellationRequest
    {
        [$request, $created] = $this->createOrFind($document, $reason, $requestedBy);

        // Unknown outcomes are intentionally never retried automatically.
        // Replays of every other state are likewise reads of the same durable
        // operation, not another provider cancellation.
        if (! $created) {
            return $request;
        }

        return $this->send($request);
    }

    public function markManualResolutionRequired(FelCancellationRequest $request, string $error): FelCancellationRequest
    {
        return DB::transaction(function () use ($request, $error) {
            $locked = FelCancellationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status === FelCancellationRequest::CONFIRMED) {
                $locked->update([
                    'status' => FelCancellationRequest::MANUAL_RESOLUTION_REQUIRED,
                    'last_error' => $error,
                    'resolved_at' => now(),
                ]);
            }

            return $locked->refresh();
        });
    }

    /** @return array{FelCancellationRequest, bool} */
    private function createOrFind(ElectronicDocument $document, string $reason, ?int $requestedBy): array
    {
        try {
            return DB::transaction(function () use ($document, $reason, $requestedBy) {
                $lockedDocument = ElectronicDocument::query()
                    ->with(['business', 'sale'])
                    ->lockForUpdate()
                    ->findOrFail($document->id);

                $existing = FelCancellationRequest::query()
                    ->where('electronic_document_id', $lockedDocument->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return [$existing, false];
                }

                if (! $lockedDocument->sale_id || ! $lockedDocument->business_id || ! filled($lockedDocument->uuid)) {
                    throw new FelException('No existe evidencia fiscal suficiente para solicitar la anulación.');
                }

                return [FelCancellationRequest::query()->create([
                    'business_id' => $lockedDocument->business_id,
                    'sale_id' => $lockedDocument->sale_id,
                    'electronic_document_id' => $lockedDocument->id,
                    'requested_by' => $requestedBy,
                    'provider' => $lockedDocument->provider,
                    'environment' => $lockedDocument->environment,
                    'uuid' => $lockedDocument->uuid,
                    'reason' => $reason,
                    'status' => FelCancellationRequest::PENDING,
                ]), true];
            });
        } catch (QueryException $exception) {
            // The unique indexes are the final concurrency guard if a second
            // worker races before it can lock the document row.
            $existing = FelCancellationRequest::query()
                ->where('electronic_document_id', $document->id)
                ->first();

            if ($existing) {
                return [$existing, false];
            }

            throw $exception;
        }
    }

    private function send(FelCancellationRequest $request): FelCancellationRequest
    {
        $request->loadMissing(['electronicDocument.business', 'electronicDocument.sale']);
        $document = $request->electronicDocument;

        try {
            $payload = $this->invoiceService->buildCancellationPayload($document, $request->reason);

            // The exact provider payload is durable before the HTTP request.
            $request = $this->updatePendingPayload($request, $payload);
            $response = $this->invoiceService->sendCancellation($document, $payload);

            return $this->complete($request, [
                'status' => FelCancellationRequest::CONFIRMED,
                'response_payload' => $response,
                'last_error' => null,
                'confirmed_at' => now(),
                'resolved_at' => now(),
            ]);
        } catch (FelProviderRejectedException $exception) {
            return $this->complete($request, [
                'status' => FelCancellationRequest::REJECTED,
                'response_payload' => $exception->responsePayload(),
                'last_error' => $exception->getMessage(),
                'resolved_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $message = $exception instanceof FelException
                ? $exception->getMessage()
                : 'No se pudo confirmar la anulación electrónica.';

            Log::error('Digifact cancellation request is uncertain', [
                'business_id' => $request->business_id,
                'sale_id' => $request->sale_id,
                'electronic_document_id' => $request->electronic_document_id,
                'fel_cancellation_request_id' => $request->id,
                'error' => $message,
            ]);

            return $this->complete($request, [
                'status' => FelCancellationRequest::UNKNOWN,
                'response_payload' => $exception instanceof FelException ? $exception->responsePayload() : null,
                'last_error' => $message,
            ]);
        }
    }

    private function updatePendingPayload(FelCancellationRequest $request, array $payload): FelCancellationRequest
    {
        return DB::transaction(function () use ($request, $payload) {
            $locked = FelCancellationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== FelCancellationRequest::PENDING) {
                return $locked;
            }

            $locked->update([
                'request_payload' => $payload,
                'attempted_at' => now(),
                'last_error' => null,
            ]);

            return $locked->refresh();
        });
    }

    private function complete(FelCancellationRequest $request, array $attributes): FelCancellationRequest
    {
        return DB::transaction(function () use ($request, $attributes) {
            $locked = FelCancellationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== FelCancellationRequest::PENDING) {
                return $locked;
            }

            $locked->update($attributes);

            return $locked->refresh();
        });
    }
}
