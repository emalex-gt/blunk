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
        $this->assertSame(-30.0, $byMethod['transfer']['purchases']);
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
        $this->assertSame(20.0, $cash['credit_payments']);
        $this->assertSame(-20.0, $cash['refunds_cancellations']);
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
        $this->assertSame(-20.0, $cancellationCard['refunds_cancellations']);
        $this->assertSame(-20.0, $cancellationCard['net']);
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
