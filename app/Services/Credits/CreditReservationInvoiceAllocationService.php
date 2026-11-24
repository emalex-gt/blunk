<?php

namespace App\Services\Credits;

use App\Models\CreditReceiptLine;
use App\Models\CreditReceiptLineInvoice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Credits;
use App\Support\Inventory\ProductQuantityPolicy;
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
}
