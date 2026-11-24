<?php

namespace App\Services\Credits;

use InvalidArgumentException;

/** Pure BCMath allocation engine; callers persist its immutable results. */
final class CreditReservationAllocation
{
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
