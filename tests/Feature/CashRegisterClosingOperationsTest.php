<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashRegisterClosingCorrection;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\TenantSetting;
use App\Models\User;
use App\Http\Controllers\CashRegisterController;
use App\Support\CashRegister;
use App\Support\CashRegisterClosingOperations;
use App\Support\CashRegisterClosingSales;
use App\Support\CashRegisterClosingSnapshot;
use App\Support\CashRegisterClosingCorrections;
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
        $this->assertSame(500.0, $cash['sales']);
        $this->assertSame(-500.0, $cash['same_turn_cancellations']);
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

    public function test_closing_snapshot_preserves_the_original_commercial_total_after_a_later_cancellation(): void
    {
        [$business, $branch, $user, $original] = $this->context();
        $sale = $this->sale($business, $branch, $user, 195);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 195, 'cash_register_session_id' => $original->id, 'status' => 'captured']);
        CashRegister::recordMovement($original, 'sale_cash', 195, 'sale', $sale->id, 'Venta', $user->id);
        $original->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()]);

        $snapshot = CashRegisterClosingSnapshot::capture($original->fresh());
        $later = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()->addMinute()]);
        $sale->update(['status' => 'cancelled', 'cancelled_at' => now()->addMinute()]);
        CashRegister::recordMovement($later, 'sale_cash_cancel', -195, 'sale', $sale->id, 'Anulación', $user->id);

        $this->assertSame(195.0, (float) $snapshot->snapshot['commercial']['total']);
        $this->assertSame(195.0, (float) CashRegisterClosingSnapshot::forSession($original->fresh())['commercial']['total']);
    }

    public function test_later_sale_cancellation_creates_one_commercial_correction_without_changing_the_original_snapshot(): void
    {
        [$business, $branch, $user, $original] = $this->context();
        $sale = $this->sale($business, $branch, $user, 195);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'card', 'amount' => 195, 'cash_register_session_id' => $original->id, 'status' => 'captured']);
        $original->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()]);
        CashRegisterClosingSnapshot::capture($original->fresh());

        $later = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()->addMinute()]);
        $sale->update(['status' => 'cancelled', 'cancelled_at' => now()->addMinute()]);

        CashRegisterClosingCorrections::recordSaleCancellation($sale->fresh(), $later, $user->id, 'Anulación posterior');
        CashRegisterClosingCorrections::recordSaleCancellation($sale->fresh(), $later, $user->id, 'Anulación posterior');

        $this->assertDatabaseCount('cash_register_closing_corrections', 1);
        $this->assertDatabaseHas('cash_register_closing_corrections', [
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'original_cash_register_session_id' => $original->id,
            'correction_cash_register_session_id' => $later->id,
            'amount' => '-195.00',
        ]);
        $this->assertSame(195.0, (float) CashRegisterClosingSnapshot::forSession($original->fresh())['commercial']['total']);
    }

    public function test_credit_sale_without_payments_cancelled_in_its_only_session_does_not_create_a_prior_period_correction(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $sale = $this->sale($business, $branch, $user, 80);
        $sale->update(['is_credit_sale' => true, 'payment_status' => 'unpaid', 'status' => 'cancelled', 'cancelled_at' => now()]);

        CashRegisterClosingCorrections::recordSaleCancellation($sale->fresh(), $session, $user->id, 'Anulación en el turno');

        $this->assertDatabaseCount('cash_register_closing_corrections', 0);
    }

    public function test_cash_sale_cancelled_in_its_original_session_does_not_create_a_corrective_receipt(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $sale = $this->sale($business, $branch, $user, 55);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 55, 'cash_register_session_id' => $session->id, 'status' => 'captured']);
        $sale->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        CashRegisterClosingCorrections::recordSaleCancellation($sale->fresh(), $session, $user->id, 'Anulación en el turno');

        $this->assertDatabaseCount('cash_register_closing_corrections', 0);
    }

    public function test_mixed_payment_cancellation_keeps_a_reliable_breakdown_without_assigning_the_total_to_one_method(): void
    {
        [$business, $branch, $user, $original] = $this->context();
        $sale = $this->sale($business, $branch, $user, 100);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 40, 'cash_register_session_id' => $original->id, 'status' => 'captured']);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'card', 'amount' => 60, 'cash_register_session_id' => $original->id, 'status' => 'captured']);
        $original->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $later = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $sale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
        CashRegisterClosingCorrections::recordSaleCancellation($sale->fresh(), $later, $user->id, 'Anulación mixta');

        $correction = CashRegisterClosingCorrection::query()->sole();
        $this->assertSame('mixed', $correction->payment_method);
        $this->assertSame(-100.0, (float) $correction->amount);
        $this->assertSame(-40.0, (float) $correction->metadata['cash_effect']);
        $this->assertCount(2, $correction->metadata['payment_breakdown']);
    }

    public function test_operation_with_undetermined_historical_origin_is_exposed_as_its_own_financial_bucket(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $sale = $this->sale($business, $branch, $user, 45);
        $sale->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'transfer', 'amount' => 45, 'status' => 'captured']);

        $transfer = collect(CashRegisterClosingOperations::forSession($session)['by_method'])->keyBy('method')['transfer'];

        $this->assertSame(45.0, $transfer['sales']);
        $this->assertSame(-45.0, $transfer['historical_unattributed']);
        $this->assertSame(0.0, $transfer['net']);
    }

    public function test_snapshot_keeps_null_branding_and_original_receipt_format_after_settings_change(): void
    {
        [$business, , $user, $session] = $this->context();
        $originalBusinessName = $business->name;
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'company_name' => null,
            'company_address' => null,
            'company_phone' => null,
            'receipt_format' => 'ticket',
        ]);
        $session->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()]);
        CashRegisterClosingSnapshot::capture($session->fresh());

        TenantSetting::query()->where('business_id', $business->id)->update([
            'company_name' => 'Marca posterior',
            'company_address' => 'Dirección posterior',
            'company_phone' => '5555-5555',
            'receipt_format' => 'document',
        ]);

        $business->forceFill([
            'name' => 'Nombre cambiado posteriormente',
        ])->save();

        $contract = CashRegisterClosingSnapshot::forSession($session->fresh()->load('closingSnapshot'));
        //$this->assertNull($contract['header']['business_name']);
        $this->assertSame(
            $originalBusinessName,
            $contract['header']['business_name']
        );
        $this->assertNull($contract['header']['business_address']);
        $this->assertNull($contract['header']['business_phone']);
        $this->assertSame('ticket', $contract['header']['receipt_format']);
    }

    public function test_document_print_renders_frozen_array_movements_without_object_property_access(): void
    {
        $this->withoutVite();
        [$business, $branch, $user, $session] = $this->context();
        CashRegister::recordMovement($session, 'opening', 50, 'cash_register_session', $session->id, 'Apertura', $user->id);
        $session->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now(), 'expected_cash' => 50, 'counted_cash' => 50, 'difference' => 0]);
        CashRegisterClosingSnapshot::capture($session->fresh());
        $contract = CashRegisterClosingSnapshot::forSession($session->fresh()->load('closingSnapshot'));

        $html = view('cash-register.closing', [
            'paperSize' => 'Letter', 'receiptFormat' => 'document',
            'company' => ['logo_url' => null, 'name' => $business->name, 'tax_id' => null, 'address' => null, 'phone' => null],
            'business' => $business, 'session' => $session->fresh(), 'summary' => $contract['cash_summary'],
            'movements' => $contract['movements'], 'paymentOperations' => $contract['payment_operations'],
            'salesSummary' => $contract['commercial'], 'closingContract' => $contract, 'timezone' => 'America/Guatemala',
        ])->render();

        $this->assertStringContainsString('Resumen de movimientos', $html);
        $this->assertStringContainsString('Apertura', $html);
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

        $this->assertSame(450.0, $summary['gross_total']);
        $this->assertSame(250.0, $summary['same_period_cancellations']);
        $this->assertSame(200.0, $summary['total']);

        $this->assertSame(4, $summary['count']);
        $this->assertSame(3, $summary['direct_count']);
        $this->assertSame(1, $summary['reconstructed_count']);
        $this->assertSame(0, $summary['ambiguous_count']);
    }

    public function test_historical_closing_total_keeps_a_sale_cancelled_in_a_later_session(): void
    {
        [$business, $branch, $user, $session] = $this->context();
        $openedAt = now()->subDays(3)->startOfDay()->addHours(8);
        $closedAt = $openedAt->copy()->addHours(8);
        $session->update([
            'status' => 'closed',
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
        ]);

        $saleAt = $openedAt->copy()->addHour();
        $cancelledAt = $closedAt->copy()->addDay()->addHour();
        $laterSession = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'closed',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => $cancelledAt->copy()->subHour(),
            'closed_at' => $cancelledAt->copy()->addHour(),
        ]);
        $sale = $this->sale($business, $branch, $user, 195);
        Sale::query()->whereKey($sale->id)->update([
            'created_at' => $saleAt,
            'updated_at' => $cancelledAt,
            'status' => 'cancelled',
            'cancelled_at' => $cancelledAt,
        ]);
        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => 195,
            'collected_at' => $saleAt,
            'cash_register_session_id' => $session->id,
            'status' => 'captured',
        ]);

        $originalSummary = CashRegisterClosingSales::forSession($session->fresh());
        $laterSummary = CashRegisterClosingSales::forSession($laterSession);

        $this->assertSame(195.0, $originalSummary['total']);
        $this->assertSame(1, $originalSummary['count']);
        $this->assertSame(0.0, $laterSummary['total']);
        $this->assertSame(0, $laterSummary['count']);
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
        $this->withoutVite();
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

        $this->assertStringContainsString('TOTAL VENDIDO DEL TURNO', $ticket);
        $this->assertStringNotContainsString('Resumen de movimientos', $ticket);
        $this->assertStringNotContainsString('Detalle de cobros y pagos', $ticket);
        $this->assertStringContainsString('Resumen de movimientos', $document);
        $this->assertStringContainsString('Detalle de cobros y pagos', $document);
    }

    public function test_sales_breakdown_keeps_later_credit_payments_out_of_original_closing(): void
    {
        [$business, $branch, $user, $session] = $this->context();

        $sale = $this->sale($business, $branch, $user, 100);
        $sale->update(['is_credit_sale' => true, 'payment_status' => 'partial']);

        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => 30,
            'cash_register_session_id' => $session->id,
            'status' => 'captured',
        ]);

        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente de prueba',
            'doc_type' => 'NIT',
            'doc_number' => '123456',
        ]);

        $account = CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'current_balance' => 70,
        ]);

        $before = CustomerCreditPayment::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_credit_account_id' => $account->id,
            'payment_number' => 1,
            'amount' => 20,
            'payment_method' => 'card',
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $sale->creditPaymentAllocations()->create([
            'business_id' => $business->id,
            'payment_id' => $before->id,
            'amount' => 20,
        ]);

        $closedAt = now()->addMinute();

        $session->update([
            'status' => 'closed',
            'closed_at' => $closedAt,
        ]);

        $after = CustomerCreditPayment::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_credit_account_id' => $account->id,
            'payment_number' => 2,
            'amount' => 50,
            'payment_method' => 'transfer',
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $after->forceFill([
            'created_at' => $closedAt->copy()->addMinute(),
        ])->save();

        $sale->creditPaymentAllocations()->create([
            'business_id' => $business->id,
            'payment_id' => $after->id,
            'amount' => 50,
        ]);

        $summary = CashRegisterClosingSales::forSession($session->fresh());
        $breakdown = $summary['payment_breakdown'];

        $this->assertSame(100.0, $summary['total']);
        $this->assertSame(30.0, $breakdown['cash']);
        $this->assertSame(20.0, $breakdown['card']);
        $this->assertSame(50.0, $breakdown['credit_pending']);
        $this->assertSame(0.0, $breakdown['transfer']);
        $this->assertSame(100.0, array_sum($breakdown));
        $this->assertSame(0, $summary['payment_breakdown_overallocated_count']);
    }
    public function test_sales_breakdown_excludes_same_turn_cancellations_and_preserves_split_payments(): void
    {
        [$business, $branch, $user, $session] = $this->context();

        $splitSale = $this->sale($business, $branch, $user, 100);

        foreach (['cash' => 40, 'card' => 60] as $method => $amount) {
            SalePayment::query()->create([
                'business_id' => $business->id,
                'sale_id' => $splitSale->id,
                'method' => $method,
                'amount' => $amount,
                'cash_register_session_id' => $session->id,
                'status' => 'captured',
            ]);
        }

        $creditSale = $this->sale($business, $branch, $user, 80);
        $creditSale->update([
            'is_credit_sale' => true,
            'payment_status' => 'unpaid',
        ]);

        $cancelledSale = $this->sale($business, $branch, $user, 200);

        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $cancelledSale->id,
            'method' => 'cash',
            'amount' => 200,
            'cash_register_session_id' => $session->id,
            'status' => 'captured',
        ]);

        $cancelledSale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $session->update([
            'status' => 'closed',
            'closed_at' => now()->addMinute(),
        ]);

        $summary = CashRegisterClosingSales::forSession($session->fresh());
        $breakdown = $summary['payment_breakdown'];

        $this->assertSame(380.0, $summary['gross_total']);
        $this->assertSame(200.0, $summary['same_period_cancellations']);
        $this->assertSame(180.0, $summary['total']);
        $this->assertSame(40.0, $breakdown['cash']);
        $this->assertSame(60.0, $breakdown['card']);
        $this->assertSame(80.0, $breakdown['credit_pending']);
        $this->assertSame(0.0, $breakdown['unclassified']);
        $this->assertSame(180.0, array_sum($breakdown));

        $snapshot = CashRegisterClosingSnapshot::capture($session->fresh());

        $this->assertSame(
            $breakdown,
            array_map(
                static fn ($amount): float => (float) $amount,
                $snapshot->snapshot['commercial']['payment_breakdown']
            )
        );
    }
    public function test_prior_period_cash_cancellation_is_separate_from_current_sales_on_ticket(): void
    {
        $this->withoutVite();

        [$business, $branch, $user, $session] = $this->context();

        $original = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'closed',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now()->subHours(4),
            'closed_at' => now()->subHours(3),
        ]);

        $oldSale = $this->sale($business, $branch, $user, 195);

        Sale::query()->whereKey($oldSale->id)->update([
            'created_at' => now()->subMinutes(210),
        ]);

        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $oldSale->id,
            'method' => 'cash',
            'amount' => 195,
            'cash_register_session_id' => $original->id,
            'collected_at' => now()->subMinutes(205),
            'status' => 'captured',
        ]);

        $session->update(['opening_amount' => 2500]);

        CashRegister::recordMovement(
            $session, 'opening', 2500,
            'cash_register_session', $session->id,
            'Apertura', $user->id
        );

        $newSale = $this->sale($business, $branch, $user, 500);

        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $newSale->id,
            'method' => 'cash',
            'amount' => 500,
            'cash_register_session_id' => $session->id,
            'status' => 'captured',
        ]);

        CashRegister::recordMovement(
            $session, 'sale_cash', 500,
            'sale', $newSale->id,
            'Venta actual', $user->id
        );

        $oldSale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        CashRegister::recordMovement(
            $session, 'sale_cash_cancel', -195,
            'sale', $oldSale->id,
            'Anulacion anterior', $user->id
        );

        CashRegisterClosingCorrections::recordSaleCancellation(
            $oldSale->fresh(),
            $session,
            $user->id,
            'Anulacion de venta anterior'
        );

        $session->update([
            'status' => 'closed',
            'closed_by' => $user->id,
            'closed_at' => now()->addMinute(),
            'expected_cash' => 2805,
            'counted_cash' => 2805,
            'difference' => 0,
        ]);

        CashRegisterClosingSnapshot::capture($session->fresh());

        $contract = CashRegisterClosingSnapshot::forSession(
            $session->fresh()->load('closingSnapshot')
        );

        $cashMethod = collect(
            $contract['payment_operations']['by_method']
        )->firstWhere('method', 'cash');

        $this->assertSame(500.0, (float) $cashMethod['sales']);
        $this->assertSame(
            -195.0,
            (float) $cashMethod['prior_period_corrections']
        );
        $this->assertSame(
            2805.0,
            (float) $contract['cash_summary']['expected_cash']
        );

        $html = view('cash-register.closing', [
            'paperSize' => 'Letter',
            'receiptFormat' => 'ticket',
            'company' => [
                'logo_url' => null,
                'name' => $business->name,
                'tax_id' => null,
                'address' => null,
                'phone' => null,
            ],
            'business' => $business,
            'session' => $session->fresh(),
            'summary' => $contract['cash_summary'],
            'movements' => $contract['movements'],
            'paymentOperations' => $contract['payment_operations'],
            'salesSummary' => $contract['commercial'],
            'closingContract' => $contract,
            'timezone' => 'America/Guatemala',
        ])->render();

        $this->assertStringContainsString(
            'Efectivo neto de ventas del turno</td><td class="right positive">'
                .formatMoney(500, $business->country),
            $html
        );

        $this->assertStringContainsString(
            'Correcciones de cierres anteriores</td><td class="right negative">-'
                .formatMoney(195, $business->country),
            $html
        );

        $this->assertStringContainsString(
            'Efectivo esperado</td><td class="right total">'
                .formatMoney(2805, $business->country),
            $html
        );

        $this->assertSame(
            2805.0,
            CashRegister::expectedCash($session->id)
        );
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
