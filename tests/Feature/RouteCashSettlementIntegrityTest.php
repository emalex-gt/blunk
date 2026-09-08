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
use App\Support\BranchInventory;
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
