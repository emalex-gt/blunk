<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Opt-in operational events.  Context is deliberately allow-listed so fiscal
 * payloads, personal data, provider replies and raw idempotency keys cannot
 * leak into an operations log by accident.
 */
final class OperationsLog
{
    private const ALLOWED_CONTEXT = [
        'branch_id',
        'user_id',
        'sale_id',
        'intent_id',
        'document_type',
        'operation_type',
        'status',
        'replayed',
        'outcome',
        'reason_code',
        'duration_ms',
        'provider',
        'environment',
        'attempt',
        'has_draft',
        'quotation_id',
        'customer_id',
    ];

    public static function event(string $event, ?int $businessId, array $context = []): void
    {
        if (! self::enabledFor($businessId)) {
            return;
        }

        $safeContext = array_intersect_key($context, array_flip(self::ALLOWED_CONTEXT));
        $safeContext['event'] = $event;

        if ($businessId !== null) {
            $safeContext['business_id'] = $businessId;
        }

        Log::channel(config('logging.operations.channel', 'blunk_operations'))->info($event, $safeContext);
    }

    private static function enabledFor(?int $businessId): bool
    {
        if (! (bool) config('logging.operations.enabled', false)) {
            return false;
        }

        $configuredBusinesses = trim((string) config('logging.operations.businesses', ''));
        if ($configuredBusinesses === '') {
            return true;
        }

        $allowedBusinesses = array_filter(array_map('intval', explode(',', $configuredBusinesses)));

        return $businessId !== null && in_array($businessId, $allowedBusinesses, true);
    }
}
