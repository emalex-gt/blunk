<?php

namespace App\Services\Credits;

use App\Models\CreditReceiptLine;
use App\Support\Inventory\ProductQuantityPolicy;
use Illuminate\Validation\ValidationException;

/**
 * Rebuilds the authoritative monetary plan for credit-reservation lines.
 *
 * Callers may pass $lockForUpdate from their own database transaction. This
 * service never persists a sale or a monetary allocation.
 */
final class CreditReservationInvoicePlanService
{
    public function __construct(private readonly CreditReservationInvoiceReadService $reader)
    {
    }

    /**
     * @param array<int, array{credit_line_id:mixed, quantity:mixed}> $requestedLines
     * @return array{lines: array<int, array<string, mixed>>, totals: ?array{gross:string,line_discount:string,general_discount:string,net:string}}
     */
    public function prepare(int $businessId, int $branchId, array $requestedLines, bool $lockForUpdate = false): array
    {
        $requested = $this->requested($requestedLines);
        $lines = $this->reader
            ->linesForInvoice($businessId, $branchId, array_column($requested, 'credit_line_id'), $lockForUpdate)
            ->keyBy('id');

        if ($lines->count() !== count($requested)) {
            $this->fail('lines', 'Una o más líneas de reserva no están disponibles para facturar en esta sucursal.');
        }

        $planLines = [];
        $totals = ['gross' => '0.00', 'line_discount' => '0.00', 'general_discount' => '0.00', 'net' => '0.00'];
        $hasHistoricalLines = false;

        foreach ($requested as $request) {
            /** @var CreditReceiptLine $line */
            $line = $lines->get($request['credit_line_id']);
            $this->assertConsistent($line, $businessId, $branchId);
            $quantity = ProductQuantityPolicy::positive($line->product, $request['quantity']);
            if (bccomp($quantity, (string) $line->qty_pending, ProductQuantityPolicy::SCALE) > 0) {
                $this->fail('quantity', 'La cantidad supera lo pendiente en la reserva.');
            }

            $contract = $this->reader->lineContract($line);
            if ($contract['snapshot_version'] === 1) {
                if ($contract['commercial']['monetary_allocation_state'] !== 'complete') {
                    $this->fail('lines', 'La reserva tiene un snapshot comercial incompleto y no puede facturarse todavía.');
                }

                $preview = $this->reader->preview($line, $quantity)['commercial'];
                $planLines[] = $this->snapshotLine($line, $quantity, $preview);
                foreach ($totals as $key => $amount) {
                    $totals[$key] = bcadd($amount, $preview[$key], 2);
                }

                continue;
            }

            $hasHistoricalLines = true;
            $planLines[] = $this->historicalLine($line, $quantity);
        }

        return [
            'lines' => $planLines,
            // A mixed operation cannot manufacture a whole-operation total:
            // future callers must compose the legacy path explicitly.
            'totals' => $hasHistoricalLines ? null : $totals,
        ];
    }

    private function snapshotLine(CreditReceiptLine $line, string $quantity, array $commercial): array
    {
        return [
            'commercial_mode' => 'snapshot',
            'credit_receipt_id' => (int) $line->credit_receipt_id,
            'credit_line_id' => (int) $line->id,
            'business_id' => (int) $line->business_id,
            'branch_id' => (int) $line->branch_id,
            'product_id' => (int) $line->product_id,
            'product_name' => $line->product?->name ?? $line->product_name,
            'quantity' => $quantity,
            'unit_price' => (string) $line->unit_price,
            'gross' => (string) $commercial['gross'],
            'line_discount' => (string) $commercial['line_discount'],
            'general_discount' => (string) $commercial['general_discount'],
            'net' => (string) $commercial['net'],
        ];
    }

    private function historicalLine(CreditReceiptLine $line, string $quantity): array
    {
        return [
            'commercial_mode' => 'historical',
            'credit_receipt_id' => (int) $line->credit_receipt_id,
            'credit_line_id' => (int) $line->id,
            'business_id' => (int) $line->business_id,
            'branch_id' => (int) $line->branch_id,
            'product_id' => (int) $line->product_id,
            'product_name' => $line->product?->name ?? $line->product_name,
            'quantity' => $quantity,
            'unit_price' => (string) $line->unit_price,
            'gross' => null,
            'line_discount' => null,
            'general_discount' => null,
            'net' => null,
        ];
    }

    private function assertConsistent(CreditReceiptLine $line, int $businessId, int $branchId): void
    {
        if (! $line->receipt
            || (int) $line->receipt->business_id !== $businessId
            || (int) $line->receipt->branch_id !== $branchId
            || ! $line->product
            || (int) $line->product->business_id !== $businessId) {
            $this->fail('lines', 'La línea de reserva tiene datos comerciales inconsistentes.');
        }
    }

    /** @return array<int, array{credit_line_id:int, quantity:mixed}> */
    private function requested(array $requestedLines): array
    {
        if ($requestedLines === []) {
            $this->fail('lines', 'Selecciona al menos una línea de reserva.');
        }

        $requested = [];
        $seen = [];
        foreach ($requestedLines as $request) {
            if (! is_array($request) || ! array_key_exists('credit_line_id', $request) || ! array_key_exists('quantity', $request)) {
                $this->fail('lines', 'Cada línea de reserva requiere identificador y cantidad.');
            }

            $id = $request['credit_line_id'];
            if ((! is_int($id) && (! is_string($id) || ! ctype_digit($id))) || (int) $id <= 0) {
                $this->fail('lines', 'La línea de reserva seleccionada no es válida.');
            }

            $lineId = (int) $id;
            if (isset($seen[$lineId])) {
                $this->fail('lines', 'Una línea de reserva no puede facturarse dos veces en la misma operación.');
            }

            $seen[$lineId] = true;
            $requested[] = ['credit_line_id' => $lineId, 'quantity' => $request['quantity']];
        }

        return $requested;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
