<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RouteCollectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_collection_contract_uses_safe_defaults_and_backfills_the_agreed_method(): void
    {
        $this->assertTrue(Schema::hasColumn('tenant_settings', 'route_collection_responsibility'));
        $this->assertTrue(Schema::hasColumn('tenant_settings', 'route_delivery_tracking'));
        $this->assertTrue(Schema::hasColumn('tenant_settings', 'route_cash_custody_policy'));
        $this->assertTrue(Schema::hasColumn('pre_sales', 'agreed_payment_method'));

        $business = Business::query()->create([
            'name' => 'Route collection defaults '.uniqid(),
            'slug' => 'route-collection-defaults-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $settings = TenantSetting::query()->create(['business_id' => $business->id])->fresh();
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente '.uniqid(),
            'country' => 'GT',
        ]);
        $seller = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
        ]);

        $this->assertSame('pre_seller', $settings->route_collection_responsibility);
        $this->assertSame('external', $settings->route_delivery_tracking);
        $this->assertSame('collector_custody_until_settlement', $settings->route_cash_custody_policy);

        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => PreSale::STATUS_DRAFT,
            'subtotal' => 0,
            'discount_total' => 0,
            'total' => 0,
            'payment_method' => 'transfer',
            'agreed_payment_method' => 'transfer',
        ]);

        $this->assertSame('transfer', $preSale->fresh()->agreed_payment_method);
    }
}
