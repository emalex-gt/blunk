<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RoutePostConversionCollectionReader;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardRouteSellerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_route_seller_without_an_open_work_day_gets_the_safe_route_home_state(): void
    {
        [$business, $seller] = $this->seller();

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.profile', 'route_seller')
                ->where('dashboard.widgets.0.key', 'routes.my_work_day')
                ->where('dashboard.widgets.0.data.has_open_work_day', false)
                ->where('dashboard.widgets.0.data.primary_action.label', 'Ver mis rutas')
                ->where('dashboard.widgets.0.data.primary_action.href', route('routes.mobile.zones', absolute: false))
                ->where('dashboard.widgets.0.data.pending_collections.count', 0));
    }

    public function test_route_seller_home_uses_only_the_current_seller_branch_and_open_work_day(): void
    {
        [$business, $seller, $branch] = $this->seller();
        $zone = $this->zone($business, $branch, $seller);
        $open = $this->workDay($business, $branch, $zone, $seller, 'open');
        $otherSeller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'pre_seller', 'is_active' => true]);
        $other = $this->workDay($business, $branch, $zone, $otherSeller, 'open');

        $this->visit($business, $branch, $open, $zone, $seller, 'pending', 1, 'Mi cliente');
        $this->visit($business, $branch, $other, $zone, $otherSeller, 'pending', 1, 'Cliente ajeno');

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.0.data.work_day.id', $open->id)
                ->where('dashboard.widgets.0.data.total_visits', 1)
                ->where('dashboard.widgets.0.data.next_visit.customer_name', 'Mi cliente'));
    }

    public function test_route_seller_home_exposes_authoritative_progress_pre_sales_and_next_visit(): void
    {
        [$business, $seller, $branch] = $this->seller();
        $zone = $this->zone($business, $branch, $seller);
        $workDay = $this->workDay($business, $branch, $zone, $seller, 'open');
        $firstPending = $this->visit($business, $branch, $workDay, $zone, $seller, 'pending', 2, 'Fiscal Uno', 'Comercial uno');
        $nextPending = $this->visit($business, $branch, $workDay, $zone, $seller, 'pending', 1, 'Fiscal Dos', 'Comercial dos');
        $this->visit($business, $branch, $workDay, $zone, $seller, 'with_pre_sale', 3, 'Con preventa');
        $this->visit($business, $branch, $workDay, $zone, $seller, 'without_sale', 4, 'Sin venta');

        foreach ([
            [PreSale::STATUS_SUBMITTED, 100],
            [PreSale::STATUS_PROCESSING, 75],
            [PreSale::STATUS_PICKED, 25],
            [PreSale::STATUS_CONVERTED, 10],
            [PreSale::STATUS_DRAFT, 30],
            [PreSale::STATUS_CANCELLED, 40],
        ] as [$status, $total]) {
            PreSale::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'route_work_day_id' => $workDay->id,
                'route_zone_id' => $zone->id,
                'customer_id' => $nextPending->customer_id,
                'seller_id' => $seller->id,
                'status' => $status,
                'subtotal' => $total,
                'discount_total' => 0,
                'total' => $total,
            ]);
        }

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.0.data.total_visits', 4)
                ->where('dashboard.widgets.0.data.visited', 2)
                ->where('dashboard.widgets.0.data.pending', 2)
                ->where('dashboard.widgets.0.data.with_pre_sale', 1)
                ->where('dashboard.widgets.0.data.without_sale', 1)
                ->where('dashboard.widgets.0.data.pre_sales_count', 4)
                ->where('dashboard.widgets.0.data.pre_sales_total', '210.00')
                ->where('dashboard.widgets.0.data.next_visit.id', $nextPending->id)
                ->where('dashboard.widgets.0.data.next_visit.customer_name', 'Comercial dos')
                ->where('dashboard.widgets.0.data.primary_action.href', route('routes.mobile.work-days.show', $workDay, absolute: false)));

        $this->assertNotSame($firstPending->id, $nextPending->id);
    }

    public function test_closed_or_foreign_tenant_work_days_are_never_current(): void
    {
        [$business, $seller, $branch] = $this->seller();
        $zone = $this->zone($business, $branch, $seller);
        $this->workDay($business, $branch, $zone, $seller, 'closed');

        [$foreignBusiness, $foreignSeller, $foreignBranch] = $this->seller();
        $foreignZone = $this->zone($foreignBusiness, $foreignBranch, $foreignSeller);
        $this->workDay($foreignBusiness, $foreignBranch, $foreignZone, $seller, 'open');

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.0.data.has_open_work_day', false));
    }

    public function test_route_seller_home_has_no_global_financial_widgets_and_exposes_cash_state_safely(): void
    {
        [$business, $seller, $branch] = $this->seller();
        $zone = $this->zone($business, $branch, $seller);
        $this->workDay($business, $branch, $zone, $seller, 'open');

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets', fn ($widgets) => collect($widgets)->pluck('key')->all() === ['routes.my_work_day'])
                ->where('dashboard.widgets.0.data.route_cash.is_open', false)
                ->where('dashboard.widgets.0.data.route_cash.message', 'No hay caja abierta para operar rutas y registrar comprobantes.'));
    }

    public function test_pending_collection_reader_is_not_called_or_exposed_without_collection_permission(): void
    {
        [$business, $seller, $branch] = $this->seller();
        $seller->roles()->detach();
        Permissions::assignDirectPermissions($seller, [Permissions::ROUTES_WORK]);
        $reader = \Mockery::mock(RoutePostConversionCollectionReader::class);
        $reader->shouldNotReceive('pendingForSeller');
        $this->app->instance(RoutePostConversionCollectionReader::class, $reader);
        $zone = $this->zone($business, $branch, $seller);
        $this->workDay($business, $branch, $zone, $seller, 'open');

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.profile', 'route_seller')
                ->where('dashboard.widgets.0.data.pending_collections', null));
    }

    public function test_route_seller_dashboard_source_disables_work_day_links_when_route_cash_is_closed(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString('const canOperateRoute = data.route_cash?.is_open === true;', $source);
        $this->assertStringContainsString('data-route-continue-action="active"', $source);
        $this->assertStringContainsString('data-route-continue-action="disabled"', $source);
        $this->assertStringContainsString('data-route-next-visit-action="active"', $source);
        $this->assertStringContainsString('data-route-next-visit-action="disabled"', $source);
        $this->assertStringContainsString('Disponible cuando la caja de rutas esté abierta.', $source);
    }

    private function seller(): array
    {
        $business = Business::query()->create([
            'name' => 'Dashboard rutas '.uniqid(),
            'slug' => 'dashboard-rutas-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);
        foreach (['routes', 'cash_register', 'branches'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }
        $seller = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
            'role' => 'pre_seller',
            'all_branches_access' => false,
            'is_active' => true,
        ]);
        Permissions::assignRole($seller, 'pre_seller');
        $seller->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]);

        return [$business, $seller, $branch];
    }

    private function zone(Business $business, Branch $branch, User $seller): RouteZone
    {
        return RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => 'Zona Centro',
            'is_active' => true,
        ]);
    }

    private function workDay(Business $business, Branch $branch, RouteZone $zone, User $seller, string $status): RouteWorkDay
    {
        return RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'work_date' => today(),
            'status' => $status,
            'started_at' => now(),
            'closed_at' => $status === 'closed' ? now() : null,
        ]);
    }

    private function visit(Business $business, Branch $branch, RouteWorkDay $workDay, RouteZone $zone, User $seller, string $status, int $order, string $name, ?string $commercialName = null): RouteVisit
    {
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'commercial_name' => $commercialName,
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'address' => 'Dirección '.$name,
            'phone' => '55550000',
        ]);

        return RouteVisit::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'visit_order' => $order,
            'status' => $status,
        ]);
    }
}
