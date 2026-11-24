<?php

namespace App\Services\Credits;

use InvalidArgumentException;

/** Pure BCMath allocation engine; callers persist its immutable results. */
final class CreditReservationAllocation
{
    /**
     * Build the immutable commercial snapshot for a newly created reservation.
     * Monetary components are kept in cents; the general discount is allocated
     * over the post-line-discount eligible amount without changing unit prices.
     */
    public function snapshot(array $lines, string $generalDiscount = '0.00'): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('La reserva requiere al menos una línea.');
        }

        $prepared = [];
        $subtotal = '0.00';
        $eligible = '0.00';

        foreach ($lines as $line) {
            $quantity = (string) ($line['quantity'] ?? '0');
            $unitPrice = (string) ($line['unit_price'] ?? '0');
            $gross = $this->round(bcmul($quantity, $unitPrice, 12));
            $lineDiscount = $this->round((string) ($line['line_discount_amount'] ?? '0'));

            if (bccomp($quantity, '0', 4) <= 0 || bccomp($unitPrice, '0', 2) < 0 || bccomp($lineDiscount, '0', 2) < 0 || bccomp($lineDiscount, $gross, 2) > 0) {
                throw new InvalidArgumentException('Línea de reserva inválida.');
            }

            $lineEligible = bcsub($gross, $lineDiscount, 2);
            $prepared[] = compact('quantity', 'unitPrice', 'gross', 'lineDiscount', 'lineEligible');
            $subtotal = bcadd($subtotal, $gross, 2);
            $eligible = bcadd($eligible, $lineEligible, 2);
        }

        $generalDiscount = $this->round($generalDiscount);
        if (bccomp($generalDiscount, '0', 2) < 0 || bccomp($generalDiscount, $eligible, 2) > 0) {
            throw new InvalidArgumentException('El descuento general excede el importe elegible.');
        }

        $remainingDiscount = $generalDiscount;
        $remainingEligible = $eligible;
        $snapshots = [];

        foreach ($prepared as $index => $line) {
            $isLast = $index === array_key_last($prepared);
            $minimum = $this->maximum('0.00', bcsub($remainingDiscount, bcsub($remainingEligible, $line['lineEligible'], 2), 2));
            $maximum = $this->minimum($remainingDiscount, $line['lineEligible']);
            $lineGeneralDiscount = $isLast
                ? $remainingDiscount
                : (bccomp($remainingEligible, '0', 2) === 0
                    ? '0.00'
                    : $this->clamp($this->proportion($remainingDiscount, $line['lineEligible'], $remainingEligible), $minimum, $maximum));
            $netAmount = bcsub($line['lineEligible'], $lineGeneralDiscount, 2);

            $snapshots[] = [
                'quantity' => $line['quantity'],
                'unit_price' => $line['unitPrice'],
                'subtotal' => $line['gross'],
                'line_discount_amount' => $line['lineDiscount'],
                'general_discount_amount' => $lineGeneralDiscount,
                'net_amount' => $netAmount,
            ];
            $remainingDiscount = bcsub($remainingDiscount, $lineGeneralDiscount, 2);
            $remainingEligible = bcsub($remainingEligible, $line['lineEligible'], 2);
        }

        return [
            'subtotal' => $subtotal,
            'line_discount_amount' => array_reduce($snapshots, fn (string $total, array $line): string => bcadd($total, $line['line_discount_amount'], 2), '0.00'),
            'general_discount_amount' => $generalDiscount,
            'total' => array_reduce($snapshots, fn (string $total, array $line): string => bcadd($total, $line['net_amount'], 2), '0.00'),
            'lines' => $snapshots,
        ];
    }

    public function allocate(array $snapshot, string $quantity, array $active = []): array
    {
        $this->valid($snapshot);
        $usedQuantity = $this->sum($active, 'quantity', 4);
        $pendingQuantity = bcsub((string) $snapshot['quantity'], $usedQuantity, 4);
        if (bccomp($quantity, '0', 4) <= 0 || bccomp($quantity, $pendingQuantity, 4) > 0) throw new InvalidArgumentException('Cantidad parcial inválida.');
        $remaining = [];
        foreach (['gross','line_discount','general_discount','net'] as $key) {
            $remaining[$key] = bcsub((string) $snapshot[$key], $this->sum($active, $key), 2);
            if (bccomp($remaining[$key], '0', 2) < 0) throw new InvalidArgumentException('Asignaciones activas exceden el snapshot.');
        }
        if (bccomp($quantity, $pendingQuantity, 4) === 0) return ['quantity'=>$quantity, ...$remaining];
        $gross = $this->proportion($remaining['gross'], $quantity, $pendingQuantity);
        $discount = bcadd($remaining['line_discount'], $remaining['general_discount'], 2);
        $minimum = $this->maximum('0.00', bcsub($discount, bcsub($remaining['gross'], $gross, 2), 2));
        $combined = $this->clamp($this->proportion($discount, $quantity, $pendingQuantity), $minimum, $this->minimum($discount, $gross));
        $line = $discount === '0.00' ? '0.00' : $this->minimum($this->proportion($remaining['line_discount'], $combined, $discount), $combined);
        $general = bcsub($combined, $line, 2);
        $net = bcsub($gross, bcadd($line, $general, 2), 2);
        if (bccomp($net, '0', 2) < 0) throw new InvalidArgumentException('El redondeo produciría un importe neto negativo.');
        return compact('quantity','gross','line','general','net') + ['line_discount'=>$line,'general_discount'=>$general];
    }

    private function proportion(string $amount, string $quantity, string $pending): string
    { return $this->round(bcdiv(bcmul($amount, $quantity, 12), $pending, 12)); }
    private function round(string $amount): string { return bcdiv(bcadd($amount, '0.005', 12), '1', 2); }
    private function minimum(string $left, string $right): string { return bccomp($left, $right, 2) <= 0 ? $left : $right; }
    private function maximum(string $left, string $right): string { return bccomp($left, $right, 2) >= 0 ? $left : $right; }
    private function clamp(string $value, string $min, string $max): string { return $this->minimum($this->maximum($value, $min), $max); }
    private function sum(array $rows, string $key, int $scale = 2): string { return array_reduce($rows, fn($v,$r)=>bcadd($v,(string)($r[$key]??0),$scale), $scale===4?'0.0000':'0.00'); }
    private function valid(array $s): void
    { if (bccomp((string)$s['quantity'],'0',4)<=0 || bccomp(bcadd((string)$s['line_discount'],bcadd((string)$s['general_discount'],(string)$s['net'],2),2),(string)$s['gross'],2)!==0) throw new InvalidArgumentException('Snapshot inválido.'); }
}
