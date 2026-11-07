<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Http\Controllers\CashRegisterController;
use App\Support\CashRegister;
use App\Support\CashRegisterClosingOperations;
use App\Support\CashRegisterClosingSales;
use App\Support\AccountsReceivable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashRegisterClosingOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_split_sale_credit_payment_purchase_and_preserves_cash_ledger(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $sale = $this->sale($business, $branch, $user, 100);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 40, 'cash_register_session_id' => $session->id, 'status' => 'captured']);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'card', 'amount' => 60, 'cash_register_session_id' => $session->id, 'status' => 'captured']);
        CashRegister::recordMovement($session, 'sale_cash', 40, 'sale', $sale->id, 'Venta', $user->id);

        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente', 'doc_type' => 'NIT', 'doc_number' => '123']);
        $account = CustomerCreditAccount::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'current_balance' => 20]);
        CustomerCreditPayment::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_credit_account_id' => $account->id, 'payment_number' => 1, 'amount' => 20, 'payment_method' => 'check', 'cash_register_session_id' => $session->id, 'status' => 'completed', 'created_by' => $user->id]);
        Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => 1, 'status' => 'completed', 'total' => 30, 'payment_method' => 'transfer', 'paid_from_cash' => false, 'cash_register_session_id' => $session->id, 'created_by' => $user->id]);
        Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => 2, 'status' => 'completed', 'total' => 99, 'payment_method' => 'credit', 'paid_from_cash' => false, 'cash_register_session_id' => $session->id, 'created_by' => $user->id]);

        $result = CashRegisterClosingOperations::forSession($session);
        $byMethod = collect($result['by_method'])->keyBy('method');

        $this->assertSame(40.0, $byMethod['cash']['sales']);
        $this->assertSame(60.0, $byMethod['card']['sales']);
        $this->assertSame(20.0, $byMethod['check']['credit_payments']);
        $this->assertSame(0.0, $byMethod['transfer']['purchases']); // Compra externa: visible en historia, no en saldo de caja.
        $this->assertSame(30.0, $byMethod['transfer']['purchases_outside_cash']);
        $this->assertSame(40.0, CashRegister::expectedCash($session->id));
        $this->assertCount(4, $result['details']);
    }

    public function test_it_reconstructs_unlinked_historical_operations_only_when_one_session_matches(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $session->update(['opened_at' => now()->subHour(), 'closed_at' => now()->addHour(), 'status' => 'closed']);
        $sale = $this->sale($business, $branch, $user, 25);
        $payment = SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'transfer', 'amount' => 25, 'status' => 'captured', 'collected_at' => now()]);

        $result = CashRegisterClosingOperations::forSession($session->fresh());

        $this->assertSame('reconstructed', $result['details'][0]['attribution']);
        $this->assertSame(25.0, collect($result['by_method'])->keyBy('method')['transfer']['sales']);
        $this->assertNull($payment->fresh()->cash_register_session_id);
    }

    public function test_direct_operations_are_isolated_between_branches(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER', 'is_active' => true]);
        $otherSession = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $other->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $sale = $this->sale($business, $other, $user, 12);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'card', 'amount' => 12, 'cash_register_session_id' => $otherSession->id, 'status' => 'captured']);

        $this->assertSame(0, CashRegisterClosingOperations::forSession($session)['detail_total']);
        $this->assertSame(1, CashRegisterClosingOperations::forSession($otherSession)['detail_total']);
    }

    public function test_new_non_cash_credit_payment_uses_the_active_operational_session_without_requiring_cash(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente de crédito', 'doc_type' => 'NIT', 'doc_number' => '456']);
        CustomerCreditAccount::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'current_balance' => 30]);

        $this->actingAs($user);
        $payment = AccountsReceivable::recordPayment($customer, $branch->id, 30, 'card', null, null, $user);

        $this->assertSame($session->id, $payment->cash_register_session_id);
        $this->assertSame(0, CashMovement::query()->where('cash_register_session_id', $session->id)->count());
    }

    public function test_cancelled_credit_payment_keeps_its_original_and_cancellation_events_in_the_same_session(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente', 'doc_type' => 'NIT', 'doc_number' => '789']);
        $account = CustomerCreditAccount::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'current_balance' => 20]);
        $payment = CustomerCreditPayment::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_credit_account_id' => $account->id,
            'payment_number' => 1,
            'amount' => 20,
            'payment_method' => 'cash',
            'cash_register_session_id' => $session->id,
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'created_by' => $user->id,
        ]);

        $result = CashRegisterClosingOperations::forSession($session);
        $details = collect($result['details'])->where('reference', 'Abono #'.$payment->payment_number);
        $cash = collect($result['by_method'])->keyBy('method')['cash'];

        $this->assertCount(2, $details);
        $this->assertSame(0.0, $cash['credit_payments']); // Abono menos su anulación.
        $this->assertSame(0.0, $cash['net']);
        $this->assertSame(0.0, CashRegister::expectedCash($session->id));
    }

    public function test_cancelled_credit_payment_is_attributed_to_the_session_of_each_event(): void
    {
        [$business, $branch, $user, $original] = $this->context();
        $original->update(['status' => 'closed', 'opened_at' => now()->subHours(2), 'closed_at' => now()->subHour()]);
        $cancellation = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now()->subMinutes(30),
        ]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente', 'doc_type' => 'NIT', 'doc_number' => '987']);
        $account = CustomerCreditAccount::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'current_balance' => 20]);
        CustomerCreditPayment::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_credit_account_id' => $account->id,
            'payment_number' => 2,
            'amount' => 20,
            'payment_method' => 'card',
            'cash_register_session_id' => $original->id,
            'status' => 'cancelled',
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(10),
            'cancelled_at' => now()->subMinutes(10),
            'created_by' => $user->id,
        ]);

        $originalCard = collect(CashRegisterClosingOperations::forSession($original->fresh())['by_method'])->keyBy('method')['card'];
        $cancellationCard = collect(CashRegisterClosingOperations::forSession($cancellation)['by_method'])->keyBy('method')['card'];

        $this->assertSame(20.0, $originalCard['credit_payments']);
        $this->assertSame(20.0, $originalCard['net']);
        $this->assertSame(-20.0, $cancellationCard['credit_payments']);
        $this->assertSame(-20.0, $cancellationCard['net']);
    }

    public function test_cash_expenses_and_cancellations_are_net_in_totals_but_individual_in_history(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        CashRegister::recordMovement($session, 'expense', -650, 'cash_expense', 11, 'Pago de luz', $user->id);
        CashRegister::recordMovement($session, 'expense_cancel', 650, 'cash_expense', 11, 'Anulación del gasto', $user->id);
        CashRegister::recordMovement($session, 'expense', -10, 'cash_expense', 12, 'Otro gasto', $user->id);

        $summary = CashRegisterClosingOperations::forSession($session);
        $cash = collect($summary['by_method'])->keyBy('method')['cash'];
        $this->assertSame(-10.0, $cash['expenses']);
        $this->assertSame(-10.0, $cash['net']);
        $this->assertSame(-10.0, CashRegister::expectedCash($session->id));
        $this->assertSame(3, $summary['detail_total']);

        $filtered = CashRegisterClosingOperations::forSession($session, 'cash', 'expense');
        $this->assertSame(2, $filtered['detail_total']);
        $this->assertSame(-10.0, collect($filtered['by_method'])->keyBy('method')['cash']['expenses']);
        $this->assertTrue(collect($filtered['details'])->every(fn ($item) => $item['kind'] === 'expense'));
    }

    public function test_sale_and_its_cancellation_net_to_zero_without_erasing_history(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $sale = $this->sale($business, $branch, $user, 500);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 500, 'cash_register_session_id' => $session->id, 'status' => 'captured']);
        $sale->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        CashRegister::recordMovement($session, 'sale_cash', 500, 'sale', $sale->id, 'Venta', $user->id);
        CashRegister::recordMovement($session, 'sale_cash_cancel', -500, 'sale', $sale->id, 'Anulación venta', $user->id);

        $result = CashRegisterClosingOperations::forSession($session);
        $cash = collect($result['by_method'])->keyBy('method')['cash'];
        $this->assertSame(0.0, $cash['sales']);
        $this->assertSame(0.0, $cash['net']);
        $this->assertSame(0.0, CashRegister::expectedCash($session->id));
        $this->assertSame(2, $result['detail_total']);
        $this->assertSame(1, CashRegisterClosingOperations::forSession($session, 'cash', 'sale_cancellation')['detail_total']);
    }

    public function test_cash_purchase_outside_register_does_not_reduce_net_but_purchase_from_register_does(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => 81, 'status' => 'completed', 'total' => 250, 'payment_method' => 'cash', 'paid_from_cash' => false, 'cash_register_session_id' => $session->id, 'created_by' => $user->id]);
        Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => 82, 'status' => 'completed', 'total' => 30, 'payment_method' => 'cash', 'paid_from_cash' => true, 'cash_register_session_id' => $session->id, 'created_by' => $user->id]);
        CashRegister::recordMovement($session, 'purchase_cash', -30, 'purchase', 82, 'Compra desde caja', $user->id);

        $result = CashRegisterClosingOperations::forSession($session);
        $cash = collect($result['by_method'])->keyBy('method')['cash'];
        $this->assertSame(-30.0, $cash['purchases']);
        $this->assertSame(250.0, $cash['purchases_outside_cash']);
        $this->assertSame(-30.0, $cash['net']);
        $this->assertSame(-30.0, CashRegister::expectedCash($session->id));
        $this->assertSame(2, CashRegisterClosingOperations::forSession($session, 'cash', 'purchase')['detail_total']);
    }

    public function test_backend_filters_after_aggregating_the_complete_history(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        for ($i = 0; $i < 205; $i++) {
            CashRegister::recordMovement($session, 'expense', -1, 'cash_expense', $i + 1, 'Gasto', $user->id);
        }
        $sale = $this->sale($business, $branch, $user, 25);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 25, 'cash_register_session_id' => $session->id, 'status' => 'captured', 'collected_at' => now()->subSeconds(30)]);
        $result = CashRegisterClosingOperations::forSession($session);
        $this->assertSame(206, $result['detail_total']);
        $this->assertCount(200, $result['details']);
        $this->assertTrue($result['has_more_details']);
        $this->assertSame(-205.0, collect($result['by_method'])->keyBy('method')['cash']['expenses']);

        $onlySale = CashRegisterClosingOperations::forSession($session, 'cash', 'sale');
        $this->assertSame(1, $onlySale['detail_total']);
        $this->assertSame('sale', $onlySale['details'][0]['kind']);

        $onlyCancelled = CashRegisterClosingOperations::forSession($session, 'cash', 'expense_cancel');
        $this->assertSame(0, $onlyCancelled['detail_total']);
        $this->assertSame(-205.0, collect($onlyCancelled['by_method'])->keyBy('method')['cash']['expenses']);
        $this->assertFalse($onlyCancelled['has_more_details']);
    }

    public function test_closing_list_payload_omits_expensive_payment_operations_but_detail_includes_them(): void
    {
        [, , , $session] = $this->context();
        $method = new \ReflectionMethod(CashRegisterController::class, 'sessionPayload');

        $listPayload = $method->invoke(new CashRegisterController, $session, 'America/Guatemala');
        $detailPayload = $method->invoke(new CashRegisterController, $session, 'America/Guatemala', true);

        $this->assertArrayNotHasKey('payment_operations', $listPayload);
        $this->assertArrayHasKey('payment_operations', $detailPayload);
    }

    public function test_commercial_sales_total_counts_each_sale_once_and_includes_unpaid_credit_sales(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $splitSale = $this->sale($business, $branch, $user, 100);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $splitSale->id, 'method' => 'cash', 'amount' => 40, 'cash_register_session_id' => $session->id, 'status' => 'captured']);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $splitSale->id, 'method' => 'card', 'amount' => 60, 'cash_register_session_id' => $session->id, 'status' => 'captured']);

        $cardSale = $this->sale($business, $branch, $user, 60);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $cardSale->id, 'method' => 'card', 'amount' => 60, 'cash_register_session_id' => $session->id, 'status' => 'captured']);

        $creditSale = $this->sale($business, $branch, $user, 40);
        $creditSale->update(['is_credit_sale' => true, 'payment_status' => 'unpaid']);

        $cancelledSale = $this->sale($business, $branch, $user, 250);
        $cancelledSale->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $cancelledSale->id, 'method' => 'cash', 'amount' => 250, 'cash_register_session_id' => $session->id, 'status' => 'captured']);

        $otherBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER', 'is_active' => true]);
        $otherSession = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $otherBranch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $otherSale = $this->sale($business, $otherBranch, $user, 500);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $otherSale->id, 'method' => 'transfer', 'amount' => 500, 'cash_register_session_id' => $otherSession->id, 'status' => 'captured']);

        $summary = CashRegisterClosingSales::forSession($session);

        $this->assertSame(200.0, $summary['total']);
        $this->assertSame(3, $summary['count']);
        $this->assertSame(2, $summary['direct_count']);
        $this->assertSame(1, $summary['reconstructed_count']);
        $this->assertSame(0, $summary['ambiguous_count']);
    }

    public function test_commercial_sales_total_excludes_timestamp_matches_when_the_session_is_ambiguous(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $session->update(['status' => 'closed', 'opened_at' => now()->subHour(), 'closed_at' => now()->addHour()]);
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'closed',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now()->subMinutes(30),
            'closed_at' => now()->addMinutes(30),
        ]);
        $this->sale($business, $branch, $user, 75);

        $summary = CashRegisterClosingSales::forSession($session->fresh());

        $this->assertSame(0.0, $summary['total']);
        $this->assertSame(0, $summary['count']);
        $this->assertSame(1, $summary['ambiguous_count']);
    }

    public function test_ticket_omits_individual_lists_while_document_keeps_closing_detail(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $sale = $this->sale($business, $branch, $user, 50);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 50, 'cash_register_session_id' => $session->id, 'status' => 'captured']);
        $paymentOperations = CashRegisterClosingOperations::forSession($session);
        $data = [
            'paperSize' => 'Letter',
            'company' => ['logo_url' => null, 'name' => $business->name, 'tax_id' => null, 'address' => null, 'phone' => null],
            'business' => $business,
            'session' => $session->fresh(),
            'summary' => CashRegister::summary($session),
            'movements' => CashRegister::movements($session),
            'paymentOperations' => $paymentOperations,
            'salesSummary' => CashRegisterClosingSales::forSession($session),
            'timezone' => 'America/Guatemala',
        ];

        $ticket = view('cash-register.closing', [...$data, 'receiptFormat' => 'ticket'])->render();
        $document = view('cash-register.closing', [...$data, 'receiptFormat' => 'document'])->render();

        $this->assertStringContainsString('TOTAL VENTAS', $ticket);
        $this->assertStringNotContainsString('Resumen de movimientos', $ticket);
        $this->assertStringNotContainsString('Detalle de cobros y pagos', $ticket);
        $this->assertStringContainsString('Resumen de movimientos', $document);
        $this->assertStringContainsString('Detalle de cobros y pagos', $document);
    }

    private function context(): array
    {
        $business = Business::query()->create(['name' => 'Cierre '.uniqid(), 'slug' => 'closing-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()->subMinute()]);
        return [$business, $branch, $user, $session];
    }

    private function sale(Business $business, Branch $branch, User $user, float $total): Sale
    {
        return Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => random_int(1, 99999), 'total' => $total, 'payment_method' => 'cash', 'status' => 'completed', 'created_by' => $user->id]);
    }
}
