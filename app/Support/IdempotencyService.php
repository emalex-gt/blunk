<?php

namespace App\Support;

use App\Models\OperationIdempotencyKey;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class IdempotencyService
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const FAILURE_KIND_VALIDATION = 'validation';

    public static function isSafelyRecoverableFailure(OperationIdempotencyKey $record): bool
    {
        return $record->status === self::STATUS_FAILED
            && ! $record->result_id
            && ! $record->result_type
            && data_get($record->response_payload, 'failure_kind') === self::FAILURE_KIND_VALIDATION;
    }

    public function replayCompleted(
        int $businessId,
        ?int $branchId,
        ?int $userId,
        string $operationType,
        string $idempotencyKey,
        array $requestPayload,
    ): ?IdempotencyResult {
        $requestHash = self::requestHash($requestPayload);

        return DB::transaction(function () use ($businessId, $branchId, $userId, $operationType, $idempotencyKey, $requestHash) {
            $record = $this->recordQuery($businessId, $branchId, $userId, $operationType, $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return null;
            }

            if (! hash_equals((string) $record->request_hash, $requestHash)) {
                throw new IdempotencyConflictException(
                    IdempotencyConflictException::PAYLOAD_MISMATCH,
                    'Esta operación ya fue usada con otros datos. Inicia una nueva operación.',
                );
            }

            if ($record->status === self::STATUS_PROCESSING) {
                throw new IdempotencyConflictException(
                    IdempotencyConflictException::PROCESSING,
                    'La operación ya se está procesando. Espera un momento.',
                );
            }

            if ($record->status !== self::STATUS_COMPLETED || ! $record->result_id) {
                return null;
            }

            $record->increment('replay_count', 1, [
                'last_replayed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->log('idempotency.replayed', $record, [
                'result_type' => $record->result_type,
                'result_id' => $record->result_id,
            ]);

            return new IdempotencyResult(
                replayed: true,
                resultId: (int) $record->result_id,
                responsePayload: $record->response_payload ?? [],
                resultType: (string) $record->result_type,
            );
        });
    }

    /**
     * Returns the exact payload that produced a completed result without
     * changing its replay counters. Callers may provide a server-normalized
     * historical representation when an older operation was hashed only after
     * immutable server-side normalization.
     *
     * @param  array<int, array<string, mixed>>  $requestPayloads
     * @return array<string, mixed>|null
     */
    public function matchingCompletedPayload(
        int $businessId,
        ?int $branchId,
        ?int $userId,
        string $operationType,
        string $idempotencyKey,
        array $requestPayloads,
    ): ?array {
        $payloadsByHash = collect($requestPayloads)
            ->mapWithKeys(fn (array $payload): array => [self::requestHash($payload) => $payload])
            ->all();

        try {
            return DB::transaction(function () use ($businessId, $branchId, $userId, $operationType, $idempotencyKey, $payloadsByHash) {
                $record = $this->recordQuery($businessId, $branchId, $userId, $operationType, $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    return null;
                }

                $payload = $payloadsByHash[(string) $record->request_hash] ?? null;

                if ($payload === null) {
                    throw new IdempotencyConflictException(
                        IdempotencyConflictException::PAYLOAD_MISMATCH,
                        'Esta operación ya fue usada con otros datos. Inicia una nueva operación.',
                    );
                }

                return $record->status === self::STATUS_COMPLETED && (bool) $record->result_id
                    ? $payload
                    : null;
            });
        } catch (IdempotencyConflictException $exception) {
            $this->recordConflict($businessId, $branchId, $userId, $operationType, $idempotencyKey, $exception->kind);

            throw $exception;
        }
    }

    public function run(
        int $businessId,
        ?int $branchId,
        ?int $userId,
        string $operationType,
        string $idempotencyKey,
        array $requestPayload,
        Closure $operation,
        string $resultType,
    ): IdempotencyResult {
        $requestHash = self::requestHash($requestPayload);

        try {
            return DB::transaction(function () use ($businessId, $branchId, $userId, $operationType, $idempotencyKey, $requestHash, $operation, $resultType) {
                $inserted = DB::table('operation_idempotency_keys')->insertOrIgnore([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'operation_type' => $operationType,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => self::STATUS_PROCESSING,
                    'locked_at' => now(),
                    'expires_at' => now()->addDay(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]) === 1;

                $record = $this->recordQuery($businessId, $branchId, $userId, $operationType, $idempotencyKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! hash_equals((string) $record->request_hash, $requestHash)) {
                    throw new IdempotencyConflictException(
                        IdempotencyConflictException::PAYLOAD_MISMATCH,
                        'Esta operación ya fue usada con otros datos. Inicia una nueva operación.',
                    );
                }

                if ($record->status === self::STATUS_COMPLETED && $record->result_id) {
                    $record->increment('replay_count', 1, [
                        'last_replayed_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->log('idempotency.replayed', $record, [
                        'result_type' => $record->result_type,
                        'result_id' => $record->result_id,
                    ]);

                    return new IdempotencyResult(
                        replayed: true,
                        resultId: (int) $record->result_id,
                        responsePayload: $record->response_payload ?? [],
                        resultType: (string) $record->result_type,
                    );
                }

                if ($record->status === self::STATUS_PROCESSING && ! $inserted) {
                    throw new IdempotencyConflictException(
                        IdempotencyConflictException::PROCESSING,
                        'La operación ya se está procesando. Espera un momento.',
                    );
                }

                $retryingFailedKey = $record->status === self::STATUS_FAILED;

                if ($retryingFailedKey) {
                    $record->update([
                        'status' => self::STATUS_PROCESSING,
                        'locked_at' => now(),
                        'last_error' => null,
                        'response_payload' => null,
                    ]);
                }

                if ($inserted || $retryingFailedKey) {
                    $this->log('idempotency.created', $record, [
                        'retrying_failed_key' => $retryingFailedKey,
                    ]);
                }

                $result = $operation();
                $resultId = is_array($result) ? (int) ($result['result_id'] ?? 0) : (int) $result;
                $responsePayload = is_array($result) ? ($result['response_payload'] ?? []) : [];
                $resolvedResultType = is_array($result) && filled($result['result_type'] ?? null)
                    ? (string) $result['result_type']
                    : $resultType;

                $record->update([
                    'status' => self::STATUS_COMPLETED,
                    'result_type' => $resolvedResultType,
                    'result_id' => $resultId,
                    'response_payload' => $responsePayload,
                    'completed_at' => now(),
                    'expires_at' => now()->addDay(),
                    'last_error' => null,
                ]);
                $this->log('idempotency.completed', $record, [
                    'result_type' => $resolvedResultType,
                    'result_id' => $resultId,
                ]);

                return new IdempotencyResult(
                    replayed: false,
                    resultId: $resultId,
                    responsePayload: $responsePayload,
                    resultType: $resolvedResultType,
                );
            });
        } catch (IdempotencyConflictException $exception) {
            $this->recordConflict($businessId, $branchId, $userId, $operationType, $idempotencyKey, $exception->kind);

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailure($businessId, $branchId, $userId, $operationType, $idempotencyKey, $requestHash, $exception);

            throw $exception;
        }
    }

    public static function requestHash(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (is_float($value)) {
                return round($value, 4);
            }

            return $value;
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            if ($key === 'idempotency_key') {
                continue;
            }

            $canonical[$key] = self::canonicalize($item);
        }

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    private function recordQuery(int $businessId, ?int $branchId, ?int $userId, string $operationType, string $idempotencyKey)
    {
        return OperationIdempotencyKey::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->where('operation_type', $operationType)
            ->where('idempotency_key', $idempotencyKey);
    }

    private function recordConflict(int $businessId, ?int $branchId, ?int $userId, string $operationType, string $idempotencyKey, string $kind): void
    {
        try {
            DB::transaction(function () use ($businessId, $branchId, $userId, $operationType, $idempotencyKey, $kind) {
                $record = $this->recordQuery($businessId, $branchId, $userId, $operationType, $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    return;
                }

                $record->increment('conflict_count', 1, [
                    'last_conflict_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->log($kind === IdempotencyConflictException::PAYLOAD_MISMATCH ? 'idempotency.conflict' : 'idempotency.processing_conflict', $record);
            });
        } catch (Throwable $telemetryException) {
            Log::warning('idempotency.telemetry_failed', [
                'event' => 'conflict',
                'operation_type' => $operationType,
                'error_class' => $telemetryException::class,
            ]);
        }
    }

    private function recordFailure(int $businessId, ?int $branchId, ?int $userId, string $operationType, string $idempotencyKey, string $requestHash, Throwable $exception): void
    {
        $failurePayload = $exception instanceof ValidationException
            ? ['failure_kind' => self::FAILURE_KIND_VALIDATION]
            : null;
        $failurePayloadJson = $failurePayload === null
            ? null
            : json_encode($failurePayload, JSON_THROW_ON_ERROR);

        try {
            DB::transaction(function () use ($businessId, $branchId, $userId, $operationType, $idempotencyKey, $requestHash, $exception, $failurePayload, $failurePayloadJson) {
                $now = now();
                DB::table('operation_idempotency_keys')->insertOrIgnore([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'operation_type' => $operationType,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => self::STATUS_FAILED,
                    'last_error' => $this->safeError($exception),
                    'response_payload' => $failurePayloadJson,
                    'expires_at' => $now->copy()->addDay(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $record = $this->recordQuery($businessId, $branchId, $userId, $operationType, $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if (! $record || in_array($record->status, [self::STATUS_COMPLETED, self::STATUS_PROCESSING], true)) {
                    return;
                }

                $record->update([
                    'status' => self::STATUS_FAILED,
                    'last_error' => $this->safeError($exception),
                    'response_payload' => $failurePayload,
                    'updated_at' => $now,
                ]);
                $this->log('idempotency.failed', $record, [
                    'error_class' => $exception::class,
                ]);
            });
        } catch (Throwable $telemetryException) {
            Log::warning('idempotency.telemetry_failed', [
                'event' => 'failed',
                'operation_type' => $operationType,
                'error_class' => $telemetryException::class,
            ]);
        }
    }

    private function safeError(Throwable $exception): string
    {
        return Str::limit(trim(strip_tags($exception->getMessage())), 1000, '...');
    }

    private function log(string $event, OperationIdempotencyKey $record, array $context = []): void
    {
        Log::info($event, [
            'business_id' => $record->business_id,
            'branch_id' => $record->branch_id,
            'user_id' => $record->user_id,
            'operation_type' => $record->operation_type,
            'idempotency_key_hash' => substr(hash('sha256', (string) $record->idempotency_key), 0, 16),
            'status' => $record->status,
            ...$context,
        ]);
    }
}
