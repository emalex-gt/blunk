<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Correlated, opt-in performance stages. This is intentionally diagnostic
 * only: it never persists or influences a commercial/fiscal operation.
 */
final class PosFelTrace
{
    public const BROWSER_PHASES = [
        'submit_clicked',
        'post_started',
        'inertia_on_success',
        'inertia_on_error',
        'inertia_on_finish',
        'destination_first_frame',
        'print_open_started',
        'print_document_loaded',
    ];

    public static function isTraceId(?string $traceId): bool
    {
        return is_string($traceId) && Str::isUuid($traceId);
    }

    public static function browserPhaseIsAllowed(string $phase): bool
    {
        return in_array($phase, self::BROWSER_PHASES, true);
    }

    /** @param array<string, mixed> $context */
    public static function record(?int $businessId, ?string $traceId, string $phase, array $context = []): void
    {
        if (! self::isTraceId($traceId)) {
            return;
        }

        OperationsLog::event('pos.sale.trace', $businessId, [
            ...$context,
            'trace_id' => $traceId,
            'phase' => $phase,
        ]);
    }
}
