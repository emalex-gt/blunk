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
