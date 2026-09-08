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
use App\Support\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RouteCashSettlementDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_reserves_a_subset_and_recalculates_its_expected_amount(): void
    {
        [$actor, $collector, $collections] = $this->fixture(2);

        $result = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [
            ['origin' => 'pre_sale_collection', 'collection_id' => $collections[0]->id],
        ], 'settlement-draft-create-0001');

        $settlement = RouteCashSettlement::query()->findOrFail($result->resultId);
        $this->assertSame('draft', $settlement->status);
        $this->assertSame('45.00', $settlement->expected_amount);
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $settlement->id,
            'route_pre_sale_collection_id' => $collections[0]->id,
            'amount_snapshot' => 45,
            'is_active' => true,
        ]);

        app(RouteCashSettlementDraftService::class)->add($settlement, $actor, [
            ['origin' => 'pre_sale_collection', 'collection_id' => $collections[1]->id],
        ], 'settlement-draft-add-0001');

        $this->assertSame('90.00', $settlement->fresh()->expected_amount);
    }

    public function test_active_collection_cannot_be_reserved_by_a_second_draft(): void
    {
        [$actor, $collector, $collections] = $this->fixture();
        $service = app(RouteCashSettlementDraftService::class);
        $service->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collections[0]->id]], 'settlement-reserve-one-0001');

        $this->expectException(ValidationException::class);
        $service->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collections[0]->id]], 'settlement-reserve-two-0001');
    }

    public function test_remove_and_cancel_release_reservations_without_changing_custody(): void
    {
        [$actor, $collector, $collections] = $this->fixture(2);
        $service = app(RouteCashSettlementDraftService::class);
        $settlement = RouteCashSettlement::query()->findOrFail($service->create($actor, $collector->id, [
            ['origin' => 'pre_sale_collection', 'collection_id' => $collections[0]->id],
            ['origin' => 'pre_sale_collection', 'collection_id' => $collections[1]->id],
        ], 'settlement-remove-create-0001')->resultId);
        $firstItem = $settlement->items()->where('route_pre_sale_collection_id', $collections[0]->id)->firstOrFail();

        $service->remove($settlement, $firstItem, $actor, 'settlement-remove-item-0001');
        $this->assertSame('45.00', $settlement->fresh()->expected_amount);
        $this->assertDatabaseHas('route_cash_settlement_items', ['id' => $firstItem->id, 'is_active' => false]);

        $service->cancel($settlement->fresh(), $actor, 'El conteo se hará después.', 'settlement-cancel-0001');
        $this->assertDatabaseHas('route_cash_settlements', ['id' => $settlement->id, 'status' => 'cancelled', 'cancelled_by' => $actor->id]);
        $this->assertDatabaseHas('route_cash_settlement_items', ['route_cash_settlement_id' => $settlement->id, 'is_active' => false]);
        $this->assertSame('held_by_collector', $collections[1]->fresh()->custody_status);
    }

    /** @return array{0: User, 1: User, 2: array<int, RoutePreSaleCollection>} */
    private function fixture(int $count = 1): array
    {
        $business = Business::query()->create(['name' => 'Settlement '.uniqid(), 'slug' => 'settlement-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $collections = [];
        for ($index = 0; $index < $count; $index++) {
            $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
            $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
            $collections[] = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        }
        return [$actor, $collector, $collections];
    }
}
