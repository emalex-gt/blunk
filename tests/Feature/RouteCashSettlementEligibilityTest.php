<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
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
use App\Models\User;
use App\Services\Routes\RouteCashSettlementEligibility;
use App\Support\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteCashSettlementEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_collector_normalizes_held_cash_from_pre_sale_and_delivery_collections(): void
    {
        [$business, $branch, $collector] = $this->scope();
        $preSaleCollection = $this->preSaleCollection($business, $branch, $collector, 125, 'cash', 'held_by_collector');
        $deliveryCollection = $this->deliveryCollection($business, $branch, $collector, 75, 'cash', 'held_by_collector');

        $eligible = app(RouteCashSettlementEligibility::class)->forCollector($business->id, $branch->id, $collector->id);

        $this->assertSame([
            ['origin' => 'route_delivery_collection', 'collection_id' => $deliveryCollection->id, 'amount' => '75.00'],
            ['origin' => 'route_pre_sale_collection', 'collection_id' => $preSaleCollection->id, 'amount' => '125.00'],
        ], $eligible->map(fn (array $item) => [
            'origin' => $item['origin'],
            'collection_id' => $item['collection_id'],
            'amount' => $item['amount'],
        ])->sortBy('origin')->values()->all());
        $this->assertTrue($eligible->every(fn (array $item) => array_key_exists('collected_at', $item)));
        $this->assertTrue($eligible->every(fn (array $item) => isset($item['business_id'], $item['branch_id'], $item['collector_user_id']) && $item['payment_method'] === 'cash' && $item['custody_status'] === 'held_by_collector'));
    }

    public function test_for_collector_excludes_non_cash_posted_reserved_and_other_scopes(): void
    {
        [$business, $branch, $collector] = $this->scope();
        $eligible = $this->preSaleCollection($business, $branch, $collector, 100, 'cash', 'held_by_collector');
        $reserved = $this->preSaleCollection($business, $branch, $collector, 101, 'cash', 'held_by_collector');
        $this->preSaleCollection($business, $branch, $collector, 102, 'card', 'not_applicable');
        $this->preSaleCollection($business, $branch, $collector, 103, 'cash', 'posted_to_branch_cash');

        $otherCollector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $this->preSaleCollection($business, $branch, $otherCollector, 104, 'cash', 'held_by_collector');
        $otherBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra '.uniqid(), 'code' => 'OTHER-'.uniqid(), 'is_active' => true]);
        $this->preSaleCollection($business, $otherBranch, $collector, 105, 'cash', 'held_by_collector');

        $settlement = RouteCashSettlement::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'collector_user_id' => $collector->id,
            'recorded_by' => $collector->id,
            'status' => 'draft',
        ]);
        RouteCashSettlementItem::query()->create([
            'route_cash_settlement_id' => $settlement->id,
            'route_pre_sale_collection_id' => $reserved->id,
            'amount_snapshot' => $reserved->amount,
            'is_active' => true,
        ]);

        $eligibleItems = app(RouteCashSettlementEligibility::class)->forCollector($business->id, $branch->id, $collector->id);

        $this->assertSame([$eligible->id], $eligibleItems->pluck('collection_id')->all());
        $this->assertSame(['route_pre_sale_collection'], $eligibleItems->pluck('origin')->all());
    }

    /** @return array{0: Business, 1: Branch, 2: User} */
    private function scope(): array
    {
        $business = Business::query()->create(['name' => 'Settlement '.uniqid(), 'slug' => 'settlement-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);

        return [$business, $branch, $collector];
    }

    private function preSaleCollection(Business $business, Branch $branch, User $collector, int $amount, string $method, string $custody): RoutePreSaleCollection
    {
        $preSale = $this->preSale($business, $branch, $collector, $amount);

        return RoutePreSaleCollection::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'pre_sale_id' => $preSale->id,
            'collected_by' => $collector->id,
            'recorded_by' => $collector->id,
            'amount' => $amount,
            'payment_method' => $method,
            'collected_at' => now(),
            'status' => 'captured',
            'custody_status' => $custody,
        ]);
    }

    private function deliveryCollection(Business $business, Branch $branch, User $collector, int $amount, string $method, string $custody): RouteDeliveryCollection
    {
        $preSale = $this->preSale($business, $branch, $collector, $amount);
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $preSale->customer_id,
            'customer_name' => $preSale->customer->name,
            'total' => $amount,
            'payment_method' => $method,
            'payment_status' => 'paid',
            'amount_paid' => $amount,
            'credit_balance' => 0,
            'is_credit_sale' => false,
            'created_by' => $collector->id,
        ]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $collector->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $collector->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $batch = RouteDeliveryBatch::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'delivered_by' => $collector->id, 'delivered_at' => now(), 'status' => 'delivered', 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent']);
        $entry = RouteDeliveryBatchPreSale::query()->create(['route_delivery_batch_id' => $batch->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'status' => 'delivered', 'payment_method' => 'cash']);
        $run = RouteDeliveryRun::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'delivery_user_id' => $collector->id, 'created_by' => $collector->id, 'status' => 'draft', 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent']);
        $stop = RouteDeliveryStop::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_delivery_run_id' => $run->id, 'route_delivery_batch_id' => $batch->id, 'route_delivery_batch_pre_sale_id' => $entry->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'customer_id' => $preSale->customer_id, 'position' => 1, 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent', 'status' => 'pending', 'assigned_by' => $collector->id, 'assigned_at' => now()]);

        return RouteDeliveryCollection::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'pre_sale_id' => $preSale->id,
            'route_delivery_stop_id' => $stop->id,
            'delivery_origin' => 'in_app_stop',
            'collected_by' => $collector->id,
            'recorded_by' => $collector->id,
            'amount' => $amount,
            'payment_method' => $method,
            'collected_at' => now(),
            'cash_custody_policy_snapshot' => 'collector_custody_until_settlement',
            'custody_status' => $custody,
            'cash_posting_state' => 'awaiting_physical_receipt',
        ]);
    }

    private function preSale(Business $business, Branch $branch, User $seller, int $amount): PreSale
    {
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);

        return PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => $amount, 'discount_total' => 0, 'total' => $amount]);
    }
}
