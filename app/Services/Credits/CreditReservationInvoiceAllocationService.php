<?php

namespace App\Services\Credits;

use App\Models\CreditReceiptLine;
use App\Models\CreditReceiptLineInvoice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Credits;
use App\Support\Inventory\ProductQuantityPolicy;
use Illuminate\Support\Collection;
use LogicException;

/** Persists the exact, already-authorized monetary allocation of a snapshot line. */
final class CreditReservationInvoiceAllocationService
{
    public function __construct(private readonly CreditReservationInvoiceReadService $reader)
    {
    }

    /** The caller owns the transaction and has already locked the reservation line. */
    public function persist(Sale $sale, SaleItem $saleItem, CreditReceiptLine $line, array $planLine): CreditReceiptLineInvoice
    {
        if (($planLine['commercial_mode'] ?? null) !== 'snapshot'
            || (int) ($planLine['credit_line_id'] ?? 0) !== (int) $line->id
            || (int) ($planLine['sale_id'] ?? $sale->id) !== (int) $sale->id) {
            throw new LogicException('La asignación comercial de la reserva no coincide con la venta.');
        }

        $existing = CreditReceiptLineInvoice::query()
            ->where('business_id', $sale->business_id)
            ->where('credit_receipt_line_id', $line->id)
            ->where('sale_id', $sale->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $quantity = ProductQuantityPolicy::canonical($planLine['quantity']);
        $allocation = CreditReceiptLineInvoice::query()->create([
            'business_id' => $sale->business_id,
            'credit_receipt_line_id' => $line->id,
            'sale_id' => $sale->id,
            'sale_line_id' => $saleItem->id,
            'quantity' => $quantity,
            'amount' => $planLine['net'],
            'subtotal_before_discount' => $planLine['gross'],
            'line_discount_amount' => $planLine['line_discount'],
            'general_discount_amount' => $planLine['general_discount'],
            'total_after_discount' => $planLine['net'],
        ]);

        $line->update([
            'qty_invoiced' => bcadd((string) $line->qty_invoiced, $quantity, ProductQuantityPolicy::SCALE),
        ]);
        $line = Credits::refreshLine($line->refresh());
        $pending = '0.00';
        if (bccomp((string) $line->qty_pending, '0', ProductQuantityPolicy::SCALE) > 0) {
            $line->load(['receipt:id,business_id,branch_id,customer_id,receipt_number,snapshot_version', 'product:id,business_id,name,allow_decimal_quantity', 'invoices' => fn ($query) => $query->whereNull('reversed_at')->orderBy('id')]);
            $contract = $this->reader->lineContract($line);
            $pending = $contract['commercial']['pending']['net'] ?? '0.00';
        }
        $line->update(['pending_total' => $pending]);
        Credits::refreshReceipt($line->receipt);

        return $allocation;
    }

    /**
     * Reverses only active snapshot allocations for an already locked ordinary
     * sale. Rows remain immutable audit evidence; repeated cancellation work is
     * therefore a no-op once reversed_at is set.
     */
    public function reverseSale(Sale $sale): void
    {
        $lineIds = CreditReceiptLineInvoice::query()
            ->where('business_id', $sale->business_id)
            ->where('sale_id', $sale->id)
            ->whereNull('reversed_at')
            ->orderBy('credit_receipt_line_id')
            ->pluck('credit_receipt_line_id')
            ->unique()
            ->values();

        if ($lineIds->isEmpty()) {
            return;
        }

        /** @var Collection<int, CreditReceiptLine> $lines */
        $lines = CreditReceiptLine::query()
            ->where('business_id', $sale->business_id)
            ->whereIn('id', $lineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $allocations = CreditReceiptLineInvoice::query()
            ->where('business_id', $sale->business_id)
            ->where('sale_id', $sale->id)
            ->whereNull('reversed_at')
            ->orderBy('credit_receipt_line_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            /** @var CreditReceiptLine|null $line */
            $line = $lines->get($allocation->credit_receipt_line_id);
            if (! $line) {
                throw new LogicException('La asignación de reserva no tiene una línea válida para revertir.');
            }

            $invoiced = bcsub((string) $line->qty_invoiced, (string) $allocation->quantity, ProductQuantityPolicy::SCALE);
            if (bccomp($invoiced, '0', ProductQuantityPolicy::SCALE) < 0) {
                throw new LogicException('La reversa de la reserva excede la cantidad facturada.');
            }

            $pending = bcsub(
                bcsub((string) $line->quantity, $invoiced, ProductQuantityPolicy::SCALE),
                (string) $line->qty_cancelled,
                ProductQuantityPolicy::SCALE,
            );
            $line->update([
                'qty_invoiced' => $invoiced,
                'qty_reserved' => ProductQuantityPolicy::minimum(
                    $pending,
                    bcadd((string) $line->qty_reserved, (string) $allocation->quantity, ProductQuantityPolicy::SCALE),
                ),
            ]);
            $allocation->update(['reversed_at' => now()]);
        }

        foreach ($lines as $line) {
            $line = Credits::refreshLine($line->refresh());
            $this->refreshSnapshotPendingTotal($line);
            Credits::refreshReceipt($line->receipt);
        }
    }

    private function refreshSnapshotPendingTotal(CreditReceiptLine $line): void
    {
        if (bccomp((string) $line->qty_pending, '0', ProductQuantityPolicy::SCALE) === 0) {
            return;
        }

        $line->load([
            'receipt:id,business_id,branch_id,customer_id,receipt_number,snapshot_version',
            'product:id,business_id,name,allow_decimal_quantity',
            'invoices' => fn ($query) => $query->whereNull('reversed_at')->orderBy('id'),
        ]);
        $contract = $this->reader->lineContract($line);
        if (($contract['commercial']['monetary_allocation_state'] ?? null) === 'complete') {
            $line->update(['pending_total' => $contract['commercial']['pending']['net']]);
        }
    }
}
