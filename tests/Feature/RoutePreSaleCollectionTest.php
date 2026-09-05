<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\CashRegisterSession;
use App\Models\CashMovement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Routes\RoutePreSaleCollectionService;
use App\Support\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoutePreSaleCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_model_is_scoped_to_a_route_pre_sale_and_tracks_auditable_collection_fields(): void
    {
        $this->assertTrue(Schema::hasTable('route_pre_sale_collections'));

        $business = Business::query()->create(['name' => 'Collections '.uniqid(), 'slug' => 'collections-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'seller_id' => $user->id,
            'status' => PreSale::STATUS_DRAFT,
            'subtotal' => 100,
            'discount_total' => 0,
            'total' => 100,
        ]);

        $collection = RoutePreSaleCollection::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'pre_sale_id' => $preSale->id,
            'collected_by' => $user->id,
            'recorded_by' => $user->id,
            'amount' => 100,
            'payment_method' => 'cash',
            'collected_at' => now(),
            'status' => 'captured',
            'custody_status' => 'held_by_collector',
        ]);

        $this->assertTrue($collection->preSale->is($preSale));
        $this->assertTrue($preSale->collections->contains($collection));
    }

    public function test_seller_can_capture_one_full_real_collection_without_inferring_it_from_the_agreed_method(): void
    {
        $business = Business::query()->create(['name' => 'Capture '.uniqid(), 'slug' => 'capture-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $seller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => PreSale::STATUS_SUBMITTED, 'subtotal' => 100, 'discount_total' => 0, 'total' => 100, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash']);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);

        $result = app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => 100,
            'payment_method' => 'cash',
            'idempotency_key' => 'capture-full-collection',
        ], $seller);

        $collection = RoutePreSaleCollection::query()->findOrFail($result->resultId);
        $this->assertSame('captured', $collection->status);
        $this->assertSame('held_by_collector', $collection->custody_status);
        $this->assertSame($seller->id, $collection->collected_by);
        $this->assertSame($seller->id, $collection->recorded_by);
        $this->assertNull($collection->cash_movement_id);
    }

    public function test_immediate_branch_register_records_one_cash_movement_for_the_collection(): void
    {
        $business = Business::query()->create(['name' => 'Custody '.uniqid(), 'slug' => 'custody-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'route_cash_custody_policy' => 'immediate_branch_register']);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $seller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => PreSale::STATUS_SUBMITTED, 'subtotal' => 100, 'discount_total' => 0, 'total' => 100]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);

        $result = app(RoutePreSaleCollectionService::class)->capture($preSale, ['amount' => 100, 'payment_method' => 'cash', 'idempotency_key' => 'capture-immediate-cash'], $seller);

        $collection = RoutePreSaleCollection::query()->findOrFail($result->resultId);
        $this->assertSame('posted_to_branch_cash', $collection->custody_status);
        $this->assertNotNull($collection->cash_movement_id);
        $this->assertSame(1, CashMovement::query()->where('reference_type', 'route_pre_sale_collection')->where('reference_id', $collection->id)->count());
    }

    public function test_sale_payment_has_a_single_nullable_route_collection_provenance_link(): void
    {
        $this->assertTrue(Schema::hasColumn('sale_payments', 'route_pre_sale_collection_id'));
        $this->assertTrue(Schema::hasColumn('sales', 'payment_method'));
    }
}
