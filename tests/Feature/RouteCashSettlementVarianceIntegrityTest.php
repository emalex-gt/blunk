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
use App\Services\Routes\RouteCashSettlementService;
use App\Services\Routes\RouteCashSettlementVarianceService;
use App\Support\BranchInventory;
use App\Support\CashRegister;
use App\Support\SystemIntegrityAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteCashSettlementVarianceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_accepts_valid_variance_ledger_and_keeps_unknown_negative_cash_invalid(): void
    {
        [$business, $actor, $collector, $collection, $session] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'integrity-variance-draft');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 40, null, 'integrity-variance-confirm', ['reason_code' => 'missing_cash', 'explanation' => 'Faltante físico.', 'confirmed' => true]);
        $variance = $settlement->fresh()->variance;
        app(RouteCashSettlementVarianceService::class)->resolve($variance, ['idempotency_key' => 'integrity-variance-resolve', 'type' => 'shortage_cash_received', 'amount' => 5, 'counterparty_user_id' => $collector->id, 'note' => 'Efectivo recibido.'], $actor);

        $validIssues = collect(app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'cash'])['results']['cash'])->pluck('issue_type');
        $this->assertFalse($validIssues->contains('route_cash_settlement_variance_mismatch'));
        $this->assertFalse($validIssues->contains('route_cash_settlement_variance_invalid_resolution'));

        CashRegister::recordMovement($session, 'unexpected_negative_type', -1, 'fixture', 1, 'Movimiento corrupto.', $actor->id);
        $invalidIssues = collect(app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'cash'])['results']['cash'])->pluck('issue_type');
        $this->assertTrue($invalidIssues->contains('invalid_negative_cash_movement'));
    }

    /** @return array{0: Business, 1: User, 2: User, 3: RoutePreSaleCollection, 4: CashRegisterSession} */
    private function fixture(): array
    {
        $business = Business::query()->create(['name' => 'Variance audit '.uniqid(), 'slug' => 'variance-audit-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        return [$business, $actor, $collector, $collection, $session];
    }
}
