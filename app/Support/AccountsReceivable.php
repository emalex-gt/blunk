<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\CustomerCreditPaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsReceivable
{
    public static function accountFor(Customer $customer, ?int $branchId = null, bool $lock = false): CustomerCreditAccount
    {
        $query = CustomerCreditAccount::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id);

        if ($lock && ($account = $query->lockForUpdate()->first())) {
            return $account;
        }

        $account = $query->first();

        if (! $account) {
            try {
                $account = CustomerCreditAccount::query()->create([
                    'business_id' => $customer->business_id,
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                ]);
            } catch (QueryException) {
                $account = $query->firstOrFail();
            }
        }

        return $lock
            ? CustomerCreditAccount::query()->lockForUpdate()->findOrFail($account->id)
            : $account;
    }

    public static function assertCanCharge(Customer $customer, float $amount, ?int $branchId = null): CustomerCreditAccount
    {
        $account = self::accountFor($customer, $branchId, true);
        $nextBalance = round((float) $account->current_balance + $amount, 2);

        if ($account->is_blocked || ($account->credit_limit !== null && $nextBalance > (float) $account->credit_limit)) {
            throw ValidationException::withMessages([
                'payment_condition' => 'El cliente no tiene crédito disponible.',
            ]);
        }

        return $account;
    }

    public static function createCharge(Sale $sale, ?int $userId = null): ?CustomerAccountMovement
    {
        if (! $sale->is_credit_sale || ! $sale->customer_id || ($sale->status ?? 'completed') === 'cancelled') {
            return null;
        }

        return DB::transaction(function () use ($sale, $userId) {
            $sale = Sale::query()
                ->where('business_id', $sale->business_id)
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $existing = CustomerAccountMovement::query()
                ->where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->where('type', 'charge')
                ->first();

            if ($existing) {
                return $existing;
            }

            $customer = Customer::query()
                ->where('business_id', $sale->business_id)
                ->lockForUpdate()
                ->findOrFail($sale->customer_id);
            $account = self::accountFor($customer, $sale->branch_id, true);
            $nextBalance = round((float) $account->current_balance + (float) $sale->total, 2);

            if ($account->is_blocked || ($account->credit_limit !== null && $nextBalance > (float) $account->credit_limit)) {
                throw ValidationException::withMessages([
                    'payment_condition' => 'El cliente no tiene crédito disponible.',
                ]);
            }

            $account->update([
                'branch_id' => $account->branch_id ?: $sale->branch_id,
                'current_balance' => $nextBalance,
            ]);

            return CustomerAccountMovement::query()->create([
                'business_id' => $sale->business_id,
                'branch_id' => $sale->branch_id,
                'customer_id' => $customer->id,
                'customer_credit_account_id' => $account->id,
                'sale_id' => $sale->id,
                'type' => 'charge',
                'direction' => 'debit',
                'description' => 'Venta al crédito '.format_sale_number($sale),
                'amount' => $sale->total,
                'balance_after' => $nextBalance,
                'created_by' => $userId,
            ]);
        });
    }

    public static function recordPayment(
        Customer $customer,
        int $branchId,
        float $amount,
        string $method,
        ?string $reference,
        ?string $notes,
        User $user,
    ): CustomerCreditPayment {
        return DB::transaction(function () use ($customer, $branchId, $amount, $method, $reference, $notes, $user) {
            $account = self::accountFor($customer, $branchId, true);
            $amount = round($amount, 2);

            if ($amount <= 0 || $amount > (float) $account->current_balance) {
                throw ValidationException::withMessages([
                    'amount' => 'El abono no puede ser mayor al saldo pendiente.',
                ]);
            }

            $cashSession = $method === 'cash'
                ? CashRegister::requireOpenSession(
                    (int) $customer->business_id,
                    'Debes tener una caja abierta para registrar abonos en efectivo.',
                    true,
                    $branchId,
                )
                : null;

            $payment = CustomerCreditPayment::query()->create([
                'business_id' => $customer->business_id,
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'customer_credit_account_id' => $account->id,
                'payment_number' => BusinessCounter::next((int) $customer->business_id, 'customer_credit_payments'),
                'amount' => $amount,
                'payment_method' => $method,
                'paid_from_cash_register' => false,
                'cash_register_session_id' => $cashSession?->id,
                'reference' => $reference,
                'notes' => $notes,
                'status' => 'completed',
                'created_by' => $user->id,
            ]);

            $nextBalance = round((float) $account->current_balance - $amount, 2);
            $account->update(['current_balance' => $nextBalance]);

            CustomerAccountMovement::query()->create([
                'business_id' => $customer->business_id,
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'customer_credit_account_id' => $account->id,
                'payment_id' => $payment->id,
                'type' => 'payment',
                'direction' => 'credit',
                'description' => 'Abono '.self::formatPaymentNumber($payment),
                'amount' => $amount,
                'balance_after' => $nextBalance,
                'payment_method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $user->id,
            ]);

            self::allocatePayment($payment, $amount);

            if ($cashSession) {
                CashRegister::recordMovement(
                    $cashSession,
                    'credit_payment_cash',
                    $amount,
                    'customer_credit_payment',
                    $payment->id,
                    'Abono de cliente '.$customer->name.' '.self::formatPaymentNumber($payment),
                    $user->id,
                );
            }

            return $payment->refresh();
        });
    }

    public static function cancelPayment(CustomerCreditPayment $payment, User $user): CustomerCreditPayment
    {
        return DB::transaction(function () use ($payment, $user) {
            $payment = CustomerCreditPayment::query()
                ->where('business_id', currentBusinessId())
                ->lockForUpdate()
                ->with(['allocations', 'cashRegisterSession'])
                ->findOrFail($payment->id);

            if ($payment->status !== 'completed') {
                throw ValidationException::withMessages(['payment' => 'Este abono ya fue anulado.']);
            }

            if ($payment->cashRegisterSession && $payment->cashRegisterSession->status !== 'open') {
                throw ValidationException::withMessages([
                    'payment' => 'No se puede anular el abono porque la caja ya fue cerrada.',
                ]);
            }

            $account = CustomerCreditAccount::query()->lockForUpdate()->findOrFail($payment->customer_credit_account_id);
            $nextBalance = round((float) $account->current_balance + (float) $payment->amount, 2);
            $account->update(['current_balance' => $nextBalance]);

            foreach ($payment->allocations as $allocation) {
                $sale = Sale::query()->lockForUpdate()->find($allocation->sale_id);

                if (! $sale) {
                    continue;
                }

                $amountPaid = max(0, round((float) $sale->amount_paid - (float) $allocation->amount, 2));
                $creditBalance = round((float) $sale->total - $amountPaid, 2);
                $sale->update([
                    'amount_paid' => $amountPaid,
                    'credit_balance' => $creditBalance,
                    'payment_status' => $amountPaid > 0 ? 'partial' : 'unpaid',
                ]);
            }

            CustomerAccountMovement::query()->create([
                'business_id' => $payment->business_id,
                'branch_id' => $payment->branch_id,
                'customer_id' => $payment->customer_id,
                'customer_credit_account_id' => $account->id,
                'payment_id' => $payment->id,
                'type' => 'cancellation',
                'direction' => 'debit',
                'description' => 'Anulación de abono '.self::formatPaymentNumber($payment),
                'amount' => $payment->amount,
                'balance_after' => $nextBalance,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'created_by' => $user->id,
            ]);

            if ($payment->cashRegisterSession) {
                CashRegister::recordMovement(
                    $payment->cashRegisterSession,
                    'credit_payment_cash_cancel',
                    -1 * (float) $payment->amount,
                    'customer_credit_payment',
                    $payment->id,
                    'Anulación '.self::formatPaymentNumber($payment),
                    $user->id,
                );
            }

            $payment->update([
                'status' => 'cancelled',
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);

            return $payment->refresh();
        });
    }

    public static function cancelSaleCharge(Sale $sale, User $user): void
    {
        if (! $sale->is_credit_sale || (float) $sale->credit_balance <= 0) {
            return;
        }

        $account = CustomerCreditAccount::query()
            ->where('business_id', $sale->business_id)
            ->where('customer_id', $sale->customer_id)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            return;
        }

        $outstanding = min((float) $sale->credit_balance, (float) $account->current_balance);
        $nextBalance = round((float) $account->current_balance - $outstanding, 2);
        $account->update(['current_balance' => $nextBalance]);

        CustomerAccountMovement::query()->create([
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'customer_id' => $sale->customer_id,
            'customer_credit_account_id' => $account->id,
            'sale_id' => $sale->id,
            'type' => 'cancellation',
            'direction' => 'credit',
            'description' => 'Anulación de venta '.format_sale_number($sale),
            'amount' => $outstanding,
            'balance_after' => $nextBalance,
            'created_by' => $user->id,
        ]);

        $sale->update(['credit_balance' => 0, 'payment_status' => 'paid']);
    }

    public static function formatPaymentNumber(CustomerCreditPayment|int|null $payment): string
    {
        $number = $payment instanceof CustomerCreditPayment ? $payment->payment_number : $payment;

        return 'AB-'.($number ?: '0');
    }

    private static function allocatePayment(CustomerCreditPayment $payment, float $amount): void
    {
        $remaining = $amount;
        $sales = Sale::query()
            ->where('business_id', $payment->business_id)
            ->where('customer_id', $payment->customer_id)
            ->where('is_credit_sale', true)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('credit_balance', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($sales as $sale) {
            if ($remaining <= 0) {
                break;
            }

            $allocated = round(min($remaining, (float) $sale->credit_balance), 2);
            $amountPaid = round((float) $sale->amount_paid + $allocated, 2);
            $creditBalance = max(0, round((float) $sale->credit_balance - $allocated, 2));

            CustomerCreditPaymentAllocation::query()->create([
                'business_id' => $payment->business_id,
                'payment_id' => $payment->id,
                'sale_id' => $sale->id,
                'amount' => $allocated,
            ]);

            $sale->update([
                'amount_paid' => $amountPaid,
                'credit_balance' => $creditBalance,
                'payment_status' => $creditBalance <= 0 ? 'paid' : 'partial',
            ]);

            $remaining = round($remaining - $allocated, 2);
        }
    }
}
