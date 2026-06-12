<?php

namespace App\Support;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CashRegister
{
    public static function currentOpenSession(int $businessId, bool $lock = false, ?int $branchId = null): ?CashRegisterSession
    {
        $query = CashRegisterSession::query()
            ->where('business_id', $businessId)
            ->where('status', 'open')
            ->latest('opened_at');

        if (Schema::hasColumn('cash_register_sessions', 'branch_id')) {
            $branchId ??= BranchInventory::activeBranch($businessId)->id;
            $query->where('branch_id', $branchId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public static function requireOpenSession(int $businessId, ?string $message = null, bool $lock = false, ?int $branchId = null): CashRegisterSession
    {
        $session = self::currentOpenSession($businessId, $lock, $branchId);

        if (! $session) {
            throw ValidationException::withMessages([
                'cash_register' => $message ?: 'No hay caja abierta.',
            ]);
        }

        return $session;
    }

    public static function ensureNoOpenSession(int $businessId): void
    {
        if (self::currentOpenSession($businessId, true)) {
            throw ValidationException::withMessages([
                'opening_amount' => 'Ya hay una caja abierta.',
            ]);
        }
    }

    public static function recordMovement(
        CashRegisterSession $session,
        string $type,
        float $amount,
        ?string $referenceType = null,
        int|string|null $referenceId = null,
        ?string $description = null,
        ?int $userId = null,
    ): CashMovement {
        $movement = CashMovement::create([
            'business_id' => $session->business_id,
            'branch_id' => $session->branch_id,
            'cash_register_session_id' => $session->id,
            'type' => $type,
            'amount' => round($amount, 2),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'created_by' => $userId,
        ]);

        $session->expected_cash = self::expectedCash($session->id);
        $session->save();

        return $movement;
    }

    public static function expectedCash(int $sessionId): float
    {
        return round((float) CashMovement::query()
            ->where('cash_register_session_id', $sessionId)
            ->sum('amount'), 2);
    }

    public static function summary(CashRegisterSession|int $session): array
    {
        $sessionId = $session instanceof CashRegisterSession ? $session->id : $session;
        $movements = CashMovement::query()
            ->where('cash_register_session_id', $sessionId)
            ->get(['type', 'amount']);

        $sum = fn (string $type): float => round((float) $movements
            ->where('type', $type)
            ->sum(fn ($movement) => (float) $movement->amount), 2);

        return [
            'opening' => $sum('opening'),
            'cash_sales' => $sum('sale_cash'),
            'credit_payments' => $sum('credit_payment_cash'),
            'credit_payment_cancellations' => abs($sum('credit_payment_cash_cancel')),
            'cash_sale_cancellations' => abs($sum('sale_cash_cancel')),
            'expenses' => abs($sum('expense')),
            'cash_purchases' => abs($sum('purchase_cash')),
            'closing_adjustments' => $sum('closing_adjustment'),
            'expected_cash' => round((float) $movements->sum(fn ($movement) => (float) $movement->amount), 2),
        ];
    }

    public static function movements(CashRegisterSession|int $session): Collection
    {
        $sessionId = $session instanceof CashRegisterSession ? $session->id : $session;

        return CashMovement::query()
            ->where('cash_register_session_id', $sessionId)
            ->with('createdBy:id,name')
            ->latest()
            ->get();
    }

    public static function cashAmountFromPayments(iterable $payments): float
    {
        $total = 0;

        foreach ($payments as $payment) {
            $method = is_array($payment) ? $payment['method'] ?? null : $payment->method;
            $amount = is_array($payment) ? $payment['amount'] ?? 0 : $payment->amount;

            if ($method === 'cash') {
                $total += (float) $amount;
            }
        }

        return round($total, 2);
    }
}
