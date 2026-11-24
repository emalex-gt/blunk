<?php

namespace App\Services\Credits;

use App\Models\CreditReceiptLine;
use App\Models\FiscalDocumentIntent;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a reservation line unavailable while a fiscal intent can still become
 * a local sale. The intent payload is the durable hold; no external call is
 * made while the row locks are held.
 */
final class CreditReservationFiscalHoldService
{
    /** @param array<int, array<string, mixed>> $planLines */
    public function assertAvailable(int $businessId, int $branchId, array $planLines): void
    {
        $lineIds = collect($planLines)
            ->pluck('credit_line_id')
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($lineIds === []) {
            return;
        }

        // Lock the stable reservation rows before looking for active durable
        // holds. A concurrent creator therefore observes the intent committed
        // by the previous holder instead of preparing the same quantity twice.
        CreditReceiptLine::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereIn('id', $lineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $active = FiscalDocumentIntent::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('operation_type', 'sale')
            ->whereIn('status', [
                FiscalDocumentIntent::PENDING,
                FiscalDocumentIntent::UNKNOWN,
                FiscalDocumentIntent::CERTIFIED,
                FiscalDocumentIntent::RETRYABLE,
            ])
            ->lockForUpdate()
            ->get(['id', 'operation_payload']);

        foreach ($active as $intent) {
            if ($this->usesAnyLine($intent->operation_payload, $lineIds)) {
                throw ValidationException::withMessages([
                    'items' => 'La reserva ya tiene una factura FEL en verificación o finalización. No se puede facturar nuevamente hasta resolverla.',
                ]);
            }
        }
    }

    /** @param array<int, int> $lineIds */
    private function usesAnyLine(mixed $payload, array $lineIds): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $held = collect(data_get($payload, 'credit_reservation_invoice_plan.lines', []))
            ->pluck('credit_line_id')
            ->merge(data_get($payload, 'credit_line_ids', []))
            ->merge(collect(data_get($payload, 'credit_line_associations.by_item_index', []))
                ->filter(fn (mixed $entry): bool => is_array($entry))
                ->pluck('credit_line_id'))
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return collect($held)->intersect($lineIds)->isNotEmpty();
    }
}
