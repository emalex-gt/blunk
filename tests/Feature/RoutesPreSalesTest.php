<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\RouteZoneCustomer;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutesPreSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_admin_can_create_route_zone_and_assign_customer(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $customer = $this->customer($business);

        $this->actingAs($admin)->post(route('routes.zones.store'), [
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => 'Ruta Centro',
            'description' => 'Centro',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $zone = RouteZone::query()->where('name', 'Ruta Centro')->firstOrFail();

        $this->actingAs($admin)->post(route('routes.zones.customers.store', $zone), [
            'customer_id' => $customer->id,
            'visit_order' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('route_zone_customers', [
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
        ]);
    }

    public function test_seller_only_sees_and_starts_assigned_zone_in_own_branch(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $otherSeller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $this->zone($business, $branch, $otherSeller, 'Otra zona');

        $this->actingAs($seller)
            ->get(route('routes.mobile.zones'))
            ->assertOk()
            ->assertSee($zone->name)
            ->assertDontSee('Otra zona');

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertRedirect();

        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Otra sucursal',
            'code' => 'B2',
            'is_active' => true,
        ]);
        $otherBranchZone = $this->zone($business, $otherBranch, $seller, 'Sucursal ajena');

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $otherBranchZone))
            ->assertSessionHasErrors('branch_id');
    }

    public function test_seller_without_branch_cannot_start_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, null, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertSessionHasErrors('branch_id');
    }

    public function test_starting_work_day_creates_visits_and_second_start_resumes_without_duplicates(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $customerA = $this->customer($business, 'Cliente A');
        $customerB = $this->customer($business, 'Cliente B');
        RouteZoneCustomer::query()->create(['business_id' => $business->id, 'route_zone_id' => $zone->id, 'customer_id' => $customerA->id, 'visit_order' => 2, 'is_active' => true]);
        RouteZoneCustomer::query()->create(['business_id' => $business->id, 'route_zone_id' => $zone->id, 'customer_id' => $customerB->id, 'visit_order' => 1, 'is_active' => true]);

        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();
        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();

        $workDay = RouteWorkDay::query()->firstOrFail();
        $this->assertSame(2, RouteVisit::query()->where('route_work_day_id', $workDay->id)->count());
        $this->assertSame($customerB->id, RouteVisit::query()->where('route_work_day_id', $workDay->id)->orderBy('visit_order')->first()->customer_id);
    }

    public function test_creating_and_editing_pre_sale_updates_stock_reservation_and_available_stock(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner', allowNegativeStock: false);
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10, salePrice: 100);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'discount' => 0]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(5.0, (float) StockReservation::query()->where('product_id', $product->id)->where('status', 'active')->value('quantity'));
        $this->assertSame(5.0, StockAvailability::availableStock($product, null, $branch->id));

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 6, 'discount' => 0]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(6.0, (float) StockReservation::query()->where('product_id', $product->id)->where('status', 'active')->value('quantity'));
        $this->assertSame(4.0, StockAvailability::availableStock($product, null, $branch->id));
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('stock'));
    }

    public function test_cancelling_draft_pre_sale_releases_reservation(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        Permissions::assignDirectPermissions($seller, [Permissions::ROUTES_PRE_SALES_CANCEL]);
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $preSale = PreSale::query()->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.pre-sales.cancel', $preSale))
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $preSale->refresh()->status);
        $this->assertSame(0, StockReservation::query()->where('status', 'active')->count());
        $this->assertSame(10.0, StockAvailability::availableStock($product, null, $branch->id));
    }

    public function test_closing_work_day_submits_pre_sales_keeps_reservations_and_does_not_deduct_stock(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $workDay = RouteWorkDay::query()->firstOrFail();
        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $workDay))->assertRedirect(route('routes.mobile.zones'));

        $this->assertSame('closed', $workDay->refresh()->status);
        $this->assertSame('submitted', PreSale::query()->firstOrFail()->status);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->count());
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('stock'));

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('pre_sale');
    }

    public function test_pre_sale_reservation_reduces_available_stock_and_negative_stock_policy_controls_over_reservation(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner', allowNegativeStock: false);
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $otherVisit = $this->startedVisit($business, $branch, $seller, 'Cliente extra');
        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $otherVisit), [
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
        ])->assertSessionHasErrors('items');

        [$allowedBusiness, , $allowedBranch] = $this->tenant(role: 'owner', allowNegativeStock: true);
        $allowedSeller = $this->user($allowedBusiness, $allowedBranch, 'pre_seller');
        $allowedProduct = $this->product($allowedBusiness, $allowedBranch, stock: 1);
        $allowedVisit = $this->startedVisit($allowedBusiness, $allowedBranch, $allowedSeller);

        $this->actingAs($allowedSeller)->post(route('routes.mobile.visits.pre-sale.store', $allowedVisit), [
            'items' => [['product_id' => $allowedProduct->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(-2.0, StockAvailability::availableStock($allowedProduct, null, $allowedBranch->id));
    }

    public function test_seller_cannot_access_another_sellers_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $otherSeller = $this->user($business, $branch, 'pre_seller');
        $visit = $this->startedVisit($business, $branch, $seller);
        $workDay = $visit->workDay;

        $this->actingAs($otherSeller)->get(route('routes.mobile.work-days.show', $workDay))->assertForbidden();
        $this->actingAs($otherSeller)->get(route('routes.mobile.visits.show', $visit))->assertForbidden();
    }

    public function test_admin_can_view_pre_sales(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch);
        $visit = $this->startedVisit($business, $branch, $seller);
        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.index'))
            ->assertOk()
            ->assertSee($visit->customer->name);
    }

    private function tenant(string $role = 'owner', bool $allowNegativeStock = false): array
    {
        $business = Business::query()->create([
            'name' => 'Routes Test',
            'slug' => 'routes-test-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'max_users' => 10,
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_manual_price' => false,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => false,
            'allow_negative_stock' => $allowNegativeStock,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        foreach (['routes', 'inventory', 'branches'] as $module) {
            TenantModule::query()->create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }

        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = $this->user($business, $branch, $role);

        return [$business, $user, $branch];
    }

    private function user(Business $business, ?Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'is_super_admin' => false,
            'current_branch_id' => $branch?->id,
        ]);
        Permissions::assignRole($user, $role);

        return $user;
    }

    private function zone(Business $business, Branch $branch, User $seller, string $name = 'Ruta Norte'): RouteZone
    {
        return RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function customer(Business $business, string $name = 'Cliente ruta'): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'doc_type' => 'NIT',
            'doc_number' => (string) random_int(1000000, 9999999),
            'address' => 'Ciudad',
            'phone' => '5555-0000',
            'country' => 'GT',
        ]);
    }

    private function product(Business $business, Branch $branch, float $stock = 10, float $salePrice = 100): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto ruta '.uniqid(),
            'code' => 'R-'.uniqid(),
            'cost_price' => 50,
            'sale_price' => $salePrice,
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        ProductBranchStock::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id],
            ['stock' => $stock],
        );

        $priceType = PriceType::query()->updateOrCreate(
            ['business_id' => $business->id, 'name' => 'General'],
            ['is_default' => true, 'is_active' => true],
        );

        ProductPrice::query()->updateOrCreate(
            ['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $priceType->id],
            ['price' => $salePrice, 'is_active' => true],
        );

        return $product;
    }

    private function startedVisit(Business $business, Branch $branch, User $seller, string $customerName = 'Cliente ruta'): RouteVisit
    {
        $zone = $this->zone($business, $branch, $seller, 'Ruta '.uniqid());
        $customer = $this->customer($business, $customerName);
        RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertRedirect();

        return RouteVisit::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->firstOrFail();
    }
}
