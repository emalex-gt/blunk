<?php

namespace App\Services\Credits;

use App\Models\CreditReceiptLine;
use App\Support\Inventory\ProductQuantityPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only contract for a future discounted credit-reservation invoice flow. */
final class CreditReservationInvoiceReadService
{
    public function __construct(private readonly CreditReservationAllocation $allocation)
    {
    }

    /** @return Collection<int, CreditReceiptLine> */
    public function linesForInvoice(int $businessId, int $branchId, array $lineIds, bool $lockForUpdate = false): Collection
    {
        return CreditReceiptLine::query()
            ->with([
                'receipt:id,business_id,branch_id,customer_id,receipt_number,snapshot_version',
                'product:id,business_id,name,allow_decimal_quantity',
                'invoices' => fn ($query) => $query
                    ->whereNull('reversed_at')
                    ->when($lockForUpdate, fn ($invoices) => $invoices->lockForUpdate())
                    ->orderBy('id'),
            ])
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereIn('id', $lineIds)
            ->whereIn('status', ['pending', 'partially_invoiced'])
            ->where('qty_pending', '>', 0)
            ->when($lockForUpdate, fn ($lines) => $lines->lockForUpdate())
            ->orderBy('id')
            ->get();
    }

    public function lineContract(CreditReceiptLine $line): array
    {
        $snapshot = $this->snapshot($line);
        $state = $snapshot === null ? 'historical' : $this->monetaryState($line, $snapshot);
        $active = $state === 'complete' ? $this->activeAssignments($line) : [];

        return [
            'credit_receipt_id' => (int) $line->credit_receipt_id,
            'credit_line_id' => (int) $line->id,
            'branch_id' => (int) $line->branch_id,
            'product' => [
                'id' => (int) $line->product_id,
                'name' => $line->product?->name ?? $line->product_name,
                'allow_decimal_quantity' => (bool) ($line->product?->allow_decimal_quantity ?? false),
            ],
            'snapshot_version' => $line->receipt?->snapshot_version === null ? null : (int) $line->receipt->snapshot_version,
            'quantity' => [
                'original' => (string) $line->quantity,
                'pending' => (string) $line->qty_pending,
                'invoiced' => (string) $line->qty_invoiced,
            ],
            'unit_price' => (string) $line->unit_price,
            'commercial' => [
                'monetary_allocation_state' => $state,
                'original' => $snapshot === null ? null : $this->commercial($snapshot),
                'pending' => $state === 'complete'
                    ? $this->commercial($this->allocation->allocate($snapshot, (string) $line->qty_pending, $active))
                    : null,
            ],
        ];
    }

    public function preview(CreditReceiptLine $line, mixed $quantity): array
    {
        $snapshot = $this->snapshot($line);
        if ($snapshot === null) {
            throw ValidationException::withMessages(['line_id' => 'La reserva histórica no tiene un snapshot comercial para vista previa.']);
        }

        if ($this->monetaryState($line, $snapshot) !== 'complete') {
            throw ValidationException::withMessages(['line_id' => 'La reserva tiene asignaciones monetarias históricas incompletas.']);
        }

        $selectedQuantity = ProductQuantityPolicy::positive($line->product, $quantity);
        if (bccomp($selectedQuantity, (string) $line->qty_pending, ProductQuantityPolicy::SCALE) > 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad supera lo pendiente en la reserva.']);
        }

        return [
            'credit_receipt_id' => (int) $line->credit_receipt_id,
            'credit_line_id' => (int) $line->id,
            'commercial' => $this->commercial($this->allocation->allocate($snapshot, $selectedQuantity, $this->activeAssignments($line))),
        ];
    }

    private function snapshot(CreditReceiptLine $line): ?array
    {
        if ((int) ($line->receipt?->snapshot_version ?? 0) !== 1) {
            return null;
        }

        $fields = [
            'subtotal_before_discount',
            'line_discount_amount',
            'general_discount_amount',
            'total_after_discount',
        ];
        foreach ($fields as $field) {
            if ($line->{$field} === null) {
                return null;
            }
        }

        return [
            'quantity' => (string) $line->quantity,
            'gross' => (string) $line->subtotal_before_discount,
            'line_discount' => (string) $line->line_discount_amount,
            'general_discount' => (string) $line->general_discount_amount,
            'net' => (string) $line->total_after_discount,
        ];
    }

    private function monetaryState(CreditReceiptLine $line, array $snapshot): string
    {
        foreach ($line->invoices as $invoice) {
            if ($invoice->subtotal_before_discount === null
                || $invoice->line_discount_amount === null
                || $invoice->general_discount_amount === null
                || $invoice->total_after_discount === null) {
                return 'incomplete';
            }
        }

        $activeQuantity = array_reduce(
            $this->activeAssignments($line),
            fn (string $total, array $assignment): string => bcadd($total, $assignment['quantity'], ProductQuantityPolicy::SCALE),
            '0.0000',
        );
        $expectedPending = bcsub($snapshot['quantity'], $activeQuantity, ProductQuantityPolicy::SCALE);

        return bccomp($expectedPending, (string) $line->qty_pending, ProductQuantityPolicy::SCALE) === 0
            ? 'complete'
            : 'incomplete';
    }

    private function activeAssignments(CreditReceiptLine $line): array
    {
        return $line->invoices->map(fn ($invoice) => [
            'quantity' => (string) $invoice->quantity,
            'gross' => (string) $invoice->subtotal_before_discount,
            'line_discount' => (string) $invoice->line_discount_amount,
            'general_discount' => (string) $invoice->general_discount_amount,
            'net' => (string) $invoice->total_after_discount,
        ])->all();
    }

    private function commercial(array $amounts): array
    {
        return [
            'quantity' => (string) $amounts['quantity'],
            'gross' => (string) $amounts['gross'],
            'line_discount' => (string) $amounts['line_discount'],
            'general_discount' => (string) $amounts['general_discount'],
            'net' => (string) $amounts['net'],
        ];
    }
}
