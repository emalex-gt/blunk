<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\PreSale;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\PriceType;
use App\Models\RouteDeliveryCollection;
use App\Models\RoutePreSaleCollection;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\SalePayment;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoutePreSalePaymentMethodPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_active_single_method_policy_assigns_the_only_method_when_create_omits_it(): void
    {
        [$business, $branch, $seller, $visit, $product] = $this->fixture();
        $this->policy($business, $branch, ['cash'], 'cash');

        $this->save($seller, $visit, $product)
            ->assertSessionHasNoErrors();

        $preSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();
        $this->assertSame('cash', $preSale->payment_method);
        $this->assertSame('cash', $preSale->agreed_payment_method);
    }

    public function test_active_single_method_policy_rejects_an_explicit_different_method(): void
    {
        [$business, $branch, $seller, $visit, $product] = $this->fixture();
        $this->policy($business, $branch, ['cash'], 'cash');

        $this->save($seller, $visit, $product, 'card')
            ->assertSessionHasErrors('payment_method');

        $this->assertDatabaseMissing('pre_sales', ['route_visit_id' => $visit->id]);
    }

    public function test_active_single_method_policy_accepts_its_explicit_method(): void
    {
        [$business, $branch, $seller, $visit, $product] = $this->fixture();
        $this->policy($business, $branch, ['transfer'], 'transfer');

        $this->save($seller, $visit, $product, 'transfer')->assertSessionHasNoErrors();

        $this->assertSame('transfer', PreSale::query()->where('route_visit_id', $visit->id)->value('agreed_payment_method'));
    }

    public function test_active_policy_accepts_card_and_check_when_the_branch_explicitly_allows_them(): void
    {
        [$business, $branch, $seller, $firstVisit, $product] = $this->fixture();
        $this->policy($business, $branch, ['card', 'check'], 'card');
        $secondVisit = $this->visit($business, $branch, $seller, 'Cliente cheque');

        $this->save($seller, $firstVisit, $product, 'card')->assertSessionHasNoErrors();
        $this->save($seller, $secondVisit, $product, 'check')->assertSessionHasNoErrors();

        $this->assertSame('card', PreSale::query()->where('route_visit_id', $firstVisit->id)->value('agreed_payment_method'));
        $this->assertSame('check', PreSale::query()->where('route_visit_id', $secondVisit->id)->value('agreed_payment_method'));
    }

    public function test_active_multiple_method_policy_uses_primary_when_omitted_and_preserves_an_allowed_secondary_selection(): void
    {
        [$business, $branch, $seller, $firstVisit, $product] = $this->fixture();
        $this->policy($business, $branch, ['cash', 'transfer'], 'cash');
        $this->save($seller, $firstVisit, $product)->assertSessionHasNoErrors();

        $secondVisit = $this->visit($business, $branch, $seller, 'Cliente secundario');
        $this->save($seller, $secondVisit, $product, 'transfer')->assertSessionHasNoErrors();
        $thirdVisit = $this->visit($business, $branch, $seller, 'Cliente principal explícito');
        $this->save($seller, $thirdVisit, $product, 'cash')->assertSessionHasNoErrors();

        $this->assertSame('cash', PreSale::query()->where('route_visit_id', $firstVisit->id)->value('agreed_payment_method'));
        $this->assertSame('cash', PreSale::query()->where('route_visit_id', $firstVisit->id)->value('payment_method'));
        $this->assertSame('transfer', PreSale::query()->where('route_visit_id', $secondVisit->id)->value('agreed_payment_method'));
        $this->assertSame('transfer', PreSale::query()->where('route_visit_id', $secondVisit->id)->value('payment_method'));
        $this->assertSame('cash', PreSale::query()->where('route_visit_id', $thirdVisit->id)->value('agreed_payment_method'));
        $this->assertSame('cash', PreSale::query()->where('route_visit_id', $thirdVisit->id)->value('payment_method'));
    }

    public function test_active_policy_rejects_unknown_or_not_allowed_methods(): void
    {
        [$business, $branch, $seller, $visit, $product] = $this->fixture();
        $this->policy($business, $branch, ['cash', 'transfer'], 'cash');

        foreach (['card', 'check', 'crypto'] as $method) {
            $this->save($seller, $visit, $product, $method)
                ->assertSessionHasErrors('payment_method');
        }

        $this->assertDatabaseMissing('pre_sales', ['route_visit_id' => $visit->id]);
    }

    public function test_active_policy_preserves_valid_secondary_on_partial_edit_and_allows_deliberate_allowed_change(): void
    {
        [$business, $branch, $seller, $visit, $product] = $this->fixture();
        $this->policy($business, $branch, ['cash', 'transfer'], 'cash');
        $this->save($seller, $visit, $product, 'transfer')->assertSessionHasNoErrors();

        $this->save($seller, $visit, $product)->assertSessionHasNoErrors();
        $this->assertSame('transfer', PreSale::query()->where('route_visit_id', $visit->id)->value('agreed_payment_method'));
        $this->assertSame('transfer', PreSale::query()->where('route_visit_id', $visit->id)->value('payment_method'));

        $this->save($seller, $visit, $product, 'cash')->assertSessionHasNoErrors();
        $this->assertSame('cash', PreSale::query()->where('route_visit_id', $visit->id)->value('agreed_payment_method'));
        $this->assertSame('cash', PreSale::query()->where('route_visit_id', $visit->id)->value('payment_method'));
    }

    public function test_existing_null_or_now_invalid_method_is_not_mutated_by_view_and_invalid_value_requires_deliberate_correction(): void
    {
        [$business, $branch, $seller, $nullVisit, $product] = $this->fixture();
        $invalidVisit = $this->visit($business, $branch, $seller, 'Cliente inválido');
        $this->save($seller, $nullVisit, $product)->assertSessionHasNoErrors();
        $this->save($seller, $invalidVisit, $product, 'check')->assertSessionHasNoErrors();
        $this->policy($business, $branch, ['cash', 'transfer'], 'cash');

        $this->actingAs($seller)->get(route('routes.mobile.visits.show', $nullVisit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment_policy.active', true)
                ->where('payment_policy.primary_method', 'cash')
                ->where('payment_policy.allowed_methods', ['cash', 'transfer']));
        $this->actingAs($seller)->get(route('routes.mobile.visits.show', $invalidVisit))->assertOk();

        $nullPreSale = PreSale::query()->where('route_visit_id', $nullVisit->id)->firstOrFail();
        $invalidPreSale = PreSale::query()->where('route_visit_id', $invalidVisit->id)->firstOrFail();
        $nullPreSale->update(['payment_method' => null, 'agreed_payment_method' => null]);
        $this->assertNull($nullPreSale->fresh()->agreed_payment_method);
        $this->assertSame('check', $invalidPreSale->fresh()->agreed_payment_method);

        $this->save($seller, $nullVisit, $product)->assertSessionHasErrors('payment_method');
        $this->assertNull($nullPreSale->fresh()->agreed_payment_method);
        $this->assertNull($nullPreSale->fresh()->payment_method);

        $this->save($seller, $nullVisit, $product, 'transfer')->assertSessionHasNoErrors();
        $this->assertSame('transfer', $nullPreSale->fresh()->agreed_payment_method);
        $this->assertSame('transfer', $nullPreSale->fresh()->payment_method);

        $this->save($seller, $invalidVisit, $product)->assertSessionHasErrors('payment_method');
        $this->assertSame('check', $invalidPreSale->fresh()->agreed_payment_method);

        $this->save($seller, $invalidVisit, $product, 'check')->assertSessionHasErrors('payment_method');
        $this->assertSame('check', $invalidPreSale->fresh()->agreed_payment_method);

        $this->save($seller, $invalidVisit, $product, 'transfer')->assertSessionHasNoErrors();
        $this->assertSame('transfer', $invalidPreSale->fresh()->agreed_payment_method);
        $this->assertSame('transfer', $invalidPreSale->fresh()->payment_method);
    }

    public function test_legacy_branch_keeps_nullable_create_and_existing_edit_behavior(): void
    {
        [$business, $branch, $seller, $visit, $product] = $this->fixture();

        $this->actingAs($seller)->get(route('routes.mobile.visits.show', $visit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payment_policy.active', false));

        $this->save($seller, $visit, $product)->assertSessionHasNoErrors();
        $preSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();
        $this->assertNull($preSale->agreed_payment_method);
        $this->assertNull($preSale->payment_method);

        $this->save($seller, $visit, $product, 'check')->assertSessionHasNoErrors();
        $this->save($seller, $visit, $product)->assertSessionHasNoErrors();
        $this->assertSame('check', $preSale->fresh()->agreed_payment_method);
        $this->assertSame('check', $preSale->fresh()->payment_method);
    }

    public function test_active_branch_policy_is_authoritative_and_payment_selection_has_no_financial_side_effects(): void
    {
        [$business, $firstBranch, $seller, $firstVisit, $product] = $this->fixture();
        $secondBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Segunda', 'code' => 'B-'.uniqid(), 'is_active' => true]);
        $secondSeller = $this->seller($business, $secondBranch);
        $secondVisit = $this->visit($business, $secondBranch, $secondSeller, 'Cliente otra branch');
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $secondBranch->id, 'product_id' => $product->id, 'stock' => 10]);
        $this->policy($business, $firstBranch, ['cash'], 'cash');
        $this->policy($business, $secondBranch, ['transfer'], 'transfer');

        $this->save($secondSeller, $secondVisit, $product, null, ['branch_id' => $firstBranch->id])->assertSessionHasNoErrors();

        $preSale = PreSale::query()->where('route_visit_id', $secondVisit->id)->firstOrFail();
        $this->assertSame('transfer', $preSale->agreed_payment_method);
        $this->assertSame(0, SalePayment::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, RoutePreSaleCollection::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, RouteDeliveryCollection::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame($secondBranch->id, $preSale->branch_id);
        $this->assertNotSame($firstVisit->branch_id, $preSale->branch_id);
    }

    public function test_mobile_pre_sale_markup_only_exposes_allowed_policy_options_and_warns_before_invalid_edit_correction(): void
    {
        $markup = file_get_contents(resource_path('js/Pages/Routes/Mobile/Visit.tsx'));

        $this->assertStringContainsString('payment_policy.active', $markup);
        $this->assertStringContainsString('allowedPaymentOptions', $markup);
        $this->assertStringContainsString('existingMethodIsInvalid', $markup);
        $this->assertStringContainsString('El método acordado actualmente ya no está disponible para esta sucursal.', $markup);
        $this->assertStringContainsString('allowedPaymentOptions.length === 1', $markup);
    }

    private function save(User $seller, RouteVisit $visit, Product $product, ?string $method = null, array $extra = [])
    {
        $payload = [
            'idempotency_key' => 'policy-'.str_replace('.', '-', uniqid('', true)),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ...$extra,
        ];
        if ($method !== null) {
            $payload['payment_method'] = $method;
        }

        return $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), $payload);
    }

    /** @return array{Business,Branch,User,RouteVisit,Product} */
    private function fixture(): array
    {
        $business = Business::query()->create(['name' => 'Método previsto '.uniqid(), 'slug' => 'method-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'products_shared_across_branches' => true, 'pricing_scope' => 'global']);
        foreach (['routes', 'inventory', 'branches'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $seller = $this->seller($business, $branch);
        $product = $this->product($business, $branch);

        return [$business, $branch, $seller, $this->visit($business, $branch, $seller, 'Cliente principal'), $product];
    }

    private function seller(Business $business, Branch $branch): User
    {
        $seller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'pre_seller', 'is_active' => true]);
        Permissions::assignRole($seller, 'pre_seller');
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now(), 'status' => 'open']);

        return $seller->fresh();
    }

    private function visit(Business $business, Branch $branch, User $seller, string $customerName): RouteVisit
    {
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => $customerName, 'country' => 'GT']);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $seller->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $seller->id, 'work_date' => today(), 'status' => 'open', 'started_at' => now()]);

        return RouteVisit::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => 'in_progress', 'visit_order' => 1]);
    }

    private function product(Business $business, Branch $branch): Product
    {
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'P-'.uniqid(), 'cost_price' => 1, 'sale_price' => 10, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $priceType = PriceType::query()->create(['business_id' => $business->id, 'name' => 'General', 'is_default' => true, 'is_active' => true]);
        ProductPrice::query()->create(['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $priceType->id, 'price' => 10, 'is_active' => true]);

        return $product;
    }

    private function policy(Business $business, Branch $branch, array $methods, string $primary): void
    {
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => $methods,
            'primary_payment_method' => $primary,
        ]);
    }
}
