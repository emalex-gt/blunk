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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RouteCashSettlementConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_creates_one_consolidated_movement_and_posts_collection_custody(): void
    {
        [$actor, $collector, $collection, $session] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'settlement-confirm-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);

        $first = app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, 'Recepción física actual.', 'settlement-confirm-0001');
        $second = app(RouteCashSettlementService::class)->confirm($settlement->fresh(), $actor, $actor->id, 45, 'Recepción física actual.', 'settlement-confirm-0001');

        $settlement->refresh();
        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame('confirmed', $settlement->status);
        $this->assertSame('45.00', $settlement->expected_amount);
        $this->assertSame('45.00', $settlement->received_amount);
        $this->assertSame('0.00', $settlement->difference_amount);
        $this->assertNotNull($settlement->cash_movement_id);
        $this->assertDatabaseHas('cash_movements', ['id' => $settlement->cash_movement_id, 'cash_register_session_id' => $session->id, 'type' => 'route_cash_settlement', 'amount' => 45, 'reference_type' => 'route_cash_settlement', 'reference_id' => $settlement->id]);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
        $this->assertNull($collection->fresh()->cash_movement_id);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_confirmation_blocks_nonzero_difference_without_changing_custody(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'settlement-difference-draft-0001');

        try {
            app(RouteCashSettlementService::class)->confirm(RouteCashSettlement::query()->findOrFail($draft->resultId), $actor, $actor->id, 44, null, 'settlement-difference-confirm-0001');
            $this->fail('Expected confirmation to reject a non-zero difference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('received_amount', $exception->errors());
        }

        $this->assertSame('draft', RouteCashSettlement::query()->findOrFail($draft->resultId)->status);
        $this->assertSame('held_by_collector', $collection->fresh()->custody_status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    /** @return array{0: User, 1: User, 2: RoutePreSaleCollection, 3: CashRegisterSession} */
    private function fixture(): array
    {
        $business = Business::query()->create(['name' => 'Confirm '.uniqid(), 'slug' => 'confirm-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);

        return [$actor, $collector, $collection, $session];
    }
}
