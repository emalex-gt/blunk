<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementReversalService;
use App\Services\Routes\RouteCashSettlementReversalEligibility;
use App\Services\Routes\RouteCashSettlementService;
use App\Support\BranchInventory;
use App\Support\CashRegister;
use App\Support\SystemIntegrityAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteCashSettlementIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_accepts_the_confirmed_settlement_chain_and_detects_a_posting_mismatch(): void
    {
        [$business, $actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [
            ['origin' => 'pre_sale_collection', 'collection_id' => $collection->id],
        ], 'settlement-audit-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'settlement-audit-confirm-0001');

        $valid = app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'cash']);
        $this->assertFalse(collect($valid['results']['cash'])->contains(fn (array $issue) => in_array($issue['issue_type'], [
            'confirmed_route_cash_settlement_invalid_movement',
            'route_cash_collection_posted_without_confirmed_settlement',
        ], true)));

        $collection->refresh()->update(['custody_status' => 'held_by_collector']);
        $invalid = app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'cash']);
        $this->assertTrue(collect($invalid['results']['cash'])->contains(fn (array $issue) => $issue['issue_type'] === 'confirmed_route_cash_settlement_collection_not_posted'), json_encode($invalid['results']['cash']));
    }

    public function test_auditor_accepts_a_valid_current_session_settlement_reversal(): void
    {
        [$business, $actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [
            ['origin' => 'pre_sale_collection', 'collection_id' => $collection->id],
        ], 'settlement-reversal-audit-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'settlement-reversal-audit-confirm-0001');

        app(RouteCashSettlementReversalService::class)->reverse(
            $settlement->fresh(),
            ['reason_code' => 'cash_not_received_by_branch', 'explanation' => 'El efectivo no llegó a sucursal.', 'confirm_cash_adjustment' => true],
            $actor,
            'settlement-reversal-audit-reverse-0001',
        );

        $session = CashRegisterSession::query()->where('business_id', $business->id)->firstOrFail();
        $summary = CashRegister::summary($session);
        $this->assertSame(0.0, $summary['expected_cash']);
        $this->assertSame(0.0, $summary['cash_refunds']);

        $issues = app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'cash'])['results']['cash'];
        $this->assertFalse(collect($issues)->contains(fn (array $issue) => in_array($issue['issue_type'], [
            'route_cash_settlement_expected_mismatch',
            'unconfirmed_route_cash_settlement_with_movement',
            'invalid_negative_cash_movement',
        ], true)), json_encode($issues));
    }

    public function test_read_side_reversal_eligibility_describes_open_current_and_closed_sessions_without_mutating(): void
    {
        [$business, $actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [
            ['origin' => 'pre_sale_collection', 'collection_id' => $collection->id],
        ], 'settlement-eligibility-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        $this->assertSame([
            'eligible' => false,
            'reason_code' => 'settlement_not_confirmed',
            'correction_mode' => null,
            'requires_cash_adjustment_confirmation' => false,
        ], app(RouteCashSettlementReversalEligibility::class)->for($settlement));
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'settlement-eligibility-confirm-0001');

        $settlement = $settlement->fresh();

        $open = app(RouteCashSettlementReversalEligibility::class)->for($settlement);
        $this->assertSame([
            'eligible' => true,
            'reason_code' => null,
            'correction_mode' => 'current_session_adjustment',
            'requires_cash_adjustment_confirmation' => true,
        ], $open);

        $settlement->cashSession->update(['status' => 'closed', 'closed_at' => now()]);
        $closed = app(RouteCashSettlementReversalEligibility::class)->for($settlement->fresh());
        $this->assertSame([
            'eligible' => true,
            'reason_code' => null,
            'correction_mode' => 'ledger_only',
            'requires_cash_adjustment_confirmation' => false,
        ], $closed);

        app(RouteCashSettlementReversalService::class)->reverse(
            $settlement->fresh(),
            ['reason_code' => 'cash_not_received_by_branch', 'explanation' => 'La sesión cerrada conserva sólo el ledger.', 'confirm_cash_adjustment' => false],
            $actor,
            'settlement-eligibility-reverse-0001',
        );
        $this->assertSame('already_reversed', app(RouteCashSettlementReversalEligibility::class)->for($settlement->fresh())['reason_code']);
    }

    /** @return array{0: Business, 1: User, 2: User, 3: RoutePreSaleCollection} */
    private function fixture(): array
    {
        $business = Business::query()->create(['name' => 'Audit '.uniqid(), 'slug' => 'audit-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);

        return [$business, $actor, $collector, $collection];
    }
}
