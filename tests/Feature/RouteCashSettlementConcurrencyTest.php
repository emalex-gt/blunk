<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteDeliveryCollection;
use App\Models\RouteDeliveryRun;
use App\Models\RouteDeliveryStop;
use App\Models\RoutePreSaleCollection;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementService;
use App\Support\BranchInventory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RouteCashSettlementConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_allows_only_one_active_reservation_for_a_collection(): void
    {
        [$business, $branch, $actor, $collector, $collection] = $this->fixture();
        $first = RouteCashSettlement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'collector_user_id' => $collector->id, 'recorded_by' => $actor->id, 'status' => 'draft']);
        $second = RouteCashSettlement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'collector_user_id' => $collector->id, 'recorded_by' => $actor->id, 'status' => 'draft']);
        RouteCashSettlementItem::query()->create(['route_cash_settlement_id' => $first->id, 'route_pre_sale_collection_id' => $collection->id, 'amount_snapshot' => 45, 'is_active' => true]);

        $this->expectException(QueryException::class);
        RouteCashSettlementItem::query()->create(['route_cash_settlement_id' => $second->id, 'route_pre_sale_collection_id' => $collection->id, 'amount_snapshot' => 45, 'is_active' => true]);
    }

    public function test_confirmation_revalidates_collection_custody_before_creating_its_single_movement(): void
    {
        [$business, $branch, $actor, $collector, $collection] = $this->fixture();
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'concurrency-stale-draft-0001');
        $collection->update(['custody_status' => 'posted_to_branch_cash']);

        $this->expectException(ValidationException::class);
        app(RouteCashSettlementService::class)->confirm(RouteCashSettlement::query()->findOrFail($draft->resultId), $actor, $actor->id, 45, null, 'concurrency-stale-confirm-0001');
    }

    public function test_database_allows_only_one_active_reservation_for_a_delivery_collection(): void
    {
        [$business, $branch, $actor, $collector] = $this->scope();
        $collection = $this->deliveryCollection($business, $branch, $collector);
        $first = RouteCashSettlement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'collector_user_id' => $collector->id, 'recorded_by' => $actor->id, 'status' => 'draft']);
        $second = RouteCashSettlement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'collector_user_id' => $collector->id, 'recorded_by' => $actor->id, 'status' => 'draft']);
        RouteCashSettlementItem::query()->create(['route_cash_settlement_id' => $first->id, 'route_delivery_collection_id' => $collection->id, 'amount_snapshot' => 45, 'is_active' => true]);

        try {
            DB::transaction(fn () => RouteCashSettlementItem::query()->create(['route_cash_settlement_id' => $second->id, 'route_delivery_collection_id' => $collection->id, 'amount_snapshot' => 45, 'is_active' => true]));
            $this->fail('Expected the active delivery collection reservation index to reject the second reservation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('route_cash_settlement_items', 1);
            $this->assertSame('held_by_collector', $collection->fresh()->custody_status);
            $this->assertDatabaseCount('cash_movements', 0);
            $this->assertDatabaseCount('sale_payments', 0);
        }
    }

    public function test_second_independently_keyed_confirmation_cannot_create_a_second_effect(): void
    {
        [$business, $branch, $actor, $collector, $collection] = $this->fixture();
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'competing-confirm-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'competing-confirm-first-0001');

        try {
            app(RouteCashSettlementService::class)->confirm($settlement->fresh(), $actor, $actor->id, 45, null, 'competing-confirm-second-0001');
            $this->fail('A second independently keyed confirmation must not be accepted.');
        } catch (ValidationException) {
            $settlement->refresh();
            $this->assertSame('confirmed', $settlement->status);
            $this->assertNotNull($settlement->confirmed_at);
            $this->assertDatabaseCount('cash_movements', 1);
            $this->assertDatabaseHas('cash_movements', ['id' => $settlement->cash_movement_id, 'type' => 'route_cash_settlement', 'reference_type' => 'route_cash_settlement', 'reference_id' => $settlement->id, 'amount' => 45]);
            $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
            $this->assertDatabaseCount('sale_payments', 0);
            $this->assertDatabaseCount('customer_account_movements', 0);
        }
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: User, 3: User, 4: RoutePreSaleCollection} */
    private function fixture(): array
    {
        $business = Business::query()->create(['name' => 'Concurrency '.uniqid(), 'slug' => 'concurrency-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        return [$business, $branch, $actor, $collector, $collection];
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: User, 3: User} */
    private function scope(): array
    {
        $business = Business::query()->create(['name' => 'Delivery concurrency '.uniqid(), 'slug' => 'delivery-concurrency-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        return [$business, $branch, $actor, $collector];
    }

    private function deliveryCollection(Business $business, \App\Models\Branch $branch, User $collector): RouteDeliveryCollection
    {
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'total' => 45, 'payment_method' => 'cash', 'payment_status' => 'paid', 'amount_paid' => 45, 'credit_balance' => 0, 'is_credit_sale' => false, 'created_by' => $collector->id]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $collector->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $collector->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $batch = RouteDeliveryBatch::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'delivered_by' => $collector->id, 'delivered_at' => now(), 'status' => 'delivered', 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent']);
        $entry = RouteDeliveryBatchPreSale::query()->create(['route_delivery_batch_id' => $batch->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'status' => 'delivered', 'payment_method' => 'cash']);
        $run = RouteDeliveryRun::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'delivery_user_id' => $collector->id, 'created_by' => $collector->id, 'status' => 'draft', 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent']);
        $stop = RouteDeliveryStop::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_delivery_run_id' => $run->id, 'route_delivery_batch_id' => $batch->id, 'route_delivery_batch_pre_sale_id' => $entry->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'customer_id' => $customer->id, 'position' => 1, 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent', 'status' => 'pending', 'assigned_by' => $collector->id, 'assigned_at' => now()]);
        return RouteDeliveryCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'sale_id' => $sale->id, 'pre_sale_id' => $preSale->id, 'route_delivery_stop_id' => $stop->id, 'delivery_origin' => 'in_app_stop', 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'cash_custody_policy_snapshot' => 'collector_custody_until_settlement', 'custody_status' => 'held_by_collector', 'cash_posting_state' => 'awaiting_physical_receipt']);
    }
}
