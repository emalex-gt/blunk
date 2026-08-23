<?php

namespace App\Support;

use App\Models\OperationIdempotencyKey;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IdempotencyService
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

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

                $record->update([
                    'status' => self::STATUS_COMPLETED,
                    'result_type' => $resultType,
                    'result_id' => $resultId,
                    'response_payload' => $responsePayload,
                    'completed_at' => now(),
                    'expires_at' => now()->addDay(),
                    'last_error' => null,
                ]);
                $this->log('idempotency.completed', $record, [
                    'result_type' => $resultType,
                    'result_id' => $resultId,
                ]);

                return new IdempotencyResult(
                    replayed: false,
                    resultId: $resultId,
                    responsePayload: $responsePayload,
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
        try {
            DB::transaction(function () use ($businessId, $branchId, $userId, $operationType, $idempotencyKey, $requestHash, $exception) {
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
