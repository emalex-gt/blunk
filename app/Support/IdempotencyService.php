<?php

namespace App\Support;

use App\Models\OperationIdempotencyKey;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

            $record = OperationIdempotencyKey::query()
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->where('user_id', $userId)
                ->where('operation_type', $operationType)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals((string) $record->request_hash, $requestHash)) {
                throw new ConflictHttpException('Esta operación ya fue usada con otros datos. Inicia una nueva operación.');
            }

            if ($record->status === self::STATUS_COMPLETED && $record->result_id) {
                return new IdempotencyResult(
                    replayed: true,
                    resultId: (int) $record->result_id,
                    responsePayload: $record->response_payload ?? [],
                );
            }

            if ($record->status === self::STATUS_PROCESSING && ! $inserted) {
                throw new ConflictHttpException('La operación ya se está procesando. Espera un momento.');
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
            ]);

            return new IdempotencyResult(
                replayed: false,
                resultId: $resultId,
                responsePayload: $responsePayload,
            );
        });
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
}
