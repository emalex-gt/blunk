<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\CashMovement;
use App\Models\CustomerCreditAccount;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockReservation;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementReversal;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Dashboard\DashboardProfileResolver;
use App\Services\Dashboard\DashboardProfiles;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\DashboardWidgetDefinition;
use App\Services\Dashboard\DashboardWidgetRegistry;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
        Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'America/Guatemala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_branch_manager_dashboard_exposes_only_authorized_widgets_with_current_and_month_sales(): void
    {
        [$business, $owner, $branch] = $this->tenant('owner');
        $product = $this->product($business, $branch, 'Martillo', 2, 3);
        $this->sale($business, $branch, $owner, $product, 100, 2, '2026-10-07 10:00:00');
        $this->sale($business, $branch, $owner, $product, 50, 1, '2026-10-03 10:00:00');
        $this->sale($business, $branch, $owner, $product, 999, 1, '2026-10-07 10:00:00', 'cancelled');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('dashboard.profile', 'branch_manager')
                ->where('dashboard.context.timezone', 'America/Guatemala')
                ->where('dashboard.widgets.0.key', 'sales.today')
                ->where('dashboard.widgets.0.data.total', '100.00')
                ->where('dashboard.widgets.0.data.count', 1)
                ->where('dashboard.widgets.0.data.cancelled_count', 1)
                ->where('dashboard.widgets.1.key', 'sales.this_month')
                ->where('dashboard.widgets.1.data.total', '150.00')
                ->where('dashboard.widgets.2.key', 'cash.current')
                ->where('dashboard.widgets.2.data.status', 'closed')
                ->where('dashboard.widgets.3.key', 'stock.alerts')
                ->where('dashboard.widgets.3.data.low_stock_count', 1)
                ->where('dashboard.widgets.4.key', 'sales.top_product')
                ->where('dashboard.widgets.4.data.name', 'Martillo')
                ->where('dashboard.widgets.5.key', 'sales.estimated_margin'));
    }

    public function test_dashboard_is_tenant_and_active_branch_scoped_and_ignores_branch_query_input(): void
    {
        [$business, $owner, $main, $other] = $this->tenant('owner', branches: true);
        $mainProduct = $this->product($business, $main, 'Sucursal principal', 2, 3);
        $otherProduct = $this->product($business, $other, 'Otra sucursal', 0, 3);
        $this->sale($business, $main, $owner, $mainProduct, 100, 2);
        $this->sale($business, $other, $owner, $otherProduct, 900, 9);

        [$otherBusiness, $otherUser, $otherBranch] = $this->tenant('owner');
        $foreignProduct = $this->product($otherBusiness, $otherBranch, 'Otro tenant', 0, 2);
        $this->sale($otherBusiness, $otherBranch, $otherUser, $foreignProduct, 5000, 5);

        $this->actingAs($owner)
            ->get(route('dashboard', ['branch_id' => $other->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.context.branch.id', $main->id)
                ->where('dashboard.widgets.0.data.total', '100.00')
                ->where('dashboard.widgets.3.data.out_of_stock_count', 1)
                ->where('dashboard.widgets.4.data.name', 'Sucursal principal'));
    }

    public function test_widgets_with_missing_modules_or_permissions_are_not_exposed(): void
    {
        [$business, $cashier, $branch] = $this->tenant('cashier', modules: ['pos']);
        $this->product($business, $branch, 'Oculto', 0, 2);

        $this->actingAs($cashier)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets', fn ($widgets) => collect($widgets)->pluck('key')->all() === ['sales.today', 'sales.this_month', 'sales.top_product'])
                ->missing('dashboard.widgets.3'));
    }

    public function test_today_uses_tenant_timezone_boundaries(): void
    {
        [$business, $owner, $branch] = $this->tenant('owner');
        $product = $this->product($business, $branch, 'Zona horaria', 4, 2);
        $this->sale($business, $branch, $owner, $product, 100, 1, '2026-10-07 00:15:00', 'completed', 'America/Guatemala');
        $this->sale($business, $branch, $owner, $product, 200, 1, '2026-10-06 23:45:00', 'completed', 'America/Guatemala');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.0.data.total', '100.00')
                ->where('dashboard.widgets.1.data.total', '300.00'));
    }

    public function test_cash_widget_uses_only_the_current_branch_open_session(): void
    {
        [$business, $owner, $main, $other] = $this->tenant('owner', branches: true);
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $other->id,
            'opened_by' => $owner->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 900,
            'opened_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.2.key', 'cash.current')
                ->where('dashboard.widgets.2.data.status', 'closed')
                ->where('dashboard.widgets.2.data.expected_cash', null));

        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'opened_by' => $owner->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 125,
            'opened_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.2.data.status', 'open')
                ->where('dashboard.widgets.2.data.expected_cash', '0.00'));
    }

    public function test_hidden_widgets_do_not_execute_their_metric_query(): void
    {
        [$business, $cashier, $branch] = $this->tenant('cashier', modules: ['pos']);
        $query = new class implements DashboardMetricQuery {
            public bool $resolved = false;

            public function resolve(DashboardContext $context, DashboardPeriod $period): array
            {
                $this->resolved = true;

                return ['unexpected' => true];
            }
        };
        $context = DashboardContext::forUser($business, $cashier);
        $service = new DashboardService([
            'sales.today' => new DashboardWidgetDefinition(
                key: 'sales.today',
                title: 'Privado',
                type: 'money',
                period: DashboardPeriod::TODAY,
                query: $query,
                requiredModules: ['pos'],
                requiredPermissions: ['permission.missing'],
            ),
        ]);

        $dashboard = $service->resolve($context);

        $this->assertSame([], $dashboard['widgets']);
        $this->assertFalse($query->resolved);
    }

    public function test_owner_simple_profile_has_unique_registry_keys_and_unknown_profiles_fail_closed(): void
    {
        $keys = DashboardProfiles::widgetKeys('owner_simple');

        $this->assertSame([
            'sales.today',
            'sales.this_month',
            'cash.current',
            'stock.alerts',
            'sales.top_product',
            'sales.estimated_margin',
        ], $keys);
        $this->assertCount(count(array_unique(array_keys(DashboardWidgetRegistry::all()))), DashboardWidgetRegistry::all());

        $this->expectException(\InvalidArgumentException::class);
        DashboardProfiles::widgetKeys('unknown_profile');
    }

    public function test_profile_resolver_uses_capabilities_for_custom_roles_and_standard_roles_only_as_preferences(): void
    {
        [$business, $user, $branch] = $this->tenant('cashier', modules: ['pos', 'reports', 'inventory', 'routes']);
        $user->roles()->detach();
        $user->update(['role' => 'custom_operations']);

        $warehouse = new DashboardContext($business, $user, $branch, false, [Permissions::INVENTORY_VIEW], ['inventory'], 'America/Guatemala');
        $executive = new DashboardContext($business, $user, $branch, false, [Permissions::REPORTS_SALES_VIEW], ['pos', 'reports'], 'America/Guatemala');
        $routes = new DashboardContext($business, $user, $branch, false, [
            Permissions::ROUTES_VIEW,
            Permissions::ROUTES_PRE_SALES_ADMIN_VIEW,
            Permissions::ROUTES_DELIVERY_RUNS_VIEW,
        ], ['routes'], 'America/Guatemala');
        $routeSeller = new DashboardContext($business, $user, $branch, false, [Permissions::ROUTES_WORK], ['routes'], 'America/Guatemala');
        $routeDelivery = new DashboardContext($business, $user, $branch, false, [Permissions::ROUTES_DELIVERY_RUNS_VIEW], ['routes'], 'America/Guatemala');
        $incompleteRouteRole = new DashboardContext($business, $user, $branch, false, [Permissions::ROUTES_VIEW], ['routes'], 'America/Guatemala');

        $resolver = new DashboardProfileResolver();

        $this->assertSame('warehouse', $resolver->resolve($warehouse));
        $this->assertSame('executive', $resolver->resolve($executive));
        $this->assertSame('route_supervisor', $resolver->resolve($routes));
        $this->assertSame('route_seller', $resolver->resolve($routeSeller));
        $this->assertSame('route_delivery', $resolver->resolve($routeDelivery));
        $this->assertSame('generic', $resolver->resolve($incompleteRouteRole));
    }

    public function test_standard_role_preferences_apply_only_after_their_capability_sets_validate(): void
    {
        [$warehouseBusiness, $stockManager] = $this->tenant('stock_manager', modules: ['inventory']);
        [$executiveBusiness, $reportsUser] = $this->tenant('reports', modules: ['pos', 'reports']);
        [$limitedBusiness, $limitedStockManager] = $this->tenant('stock_manager', modules: ['pos', 'reports']);
        [$ownerBusiness, $owner] = $this->tenant('owner', modules: ['pos', 'reports', 'routes']);
        [$adminBusiness, $admin] = $this->tenant('admin', modules: ['pos', 'routes']);
        Permissions::assignDirectPermissions($owner, [
            Permissions::ROUTES_VIEW,
            Permissions::ROUTES_PRE_SALES_ADMIN_VIEW,
            Permissions::ROUTES_DELIVERY_RUNS_VIEW,
        ]);
        Permissions::assignDirectPermissions($admin, [
            Permissions::ROUTES_VIEW,
            Permissions::ROUTES_PRE_SALES_ADMIN_VIEW,
            Permissions::ROUTES_DELIVERY_RUNS_VIEW,
        ]);
        $resolver = new DashboardProfileResolver();

        $this->assertSame('warehouse', $resolver->resolve(DashboardContext::forUser($warehouseBusiness, $stockManager)));
        $this->assertSame('finance', $resolver->resolve(DashboardContext::forUser($executiveBusiness, $reportsUser)));
        $this->assertSame('generic', $resolver->resolve(DashboardContext::forUser($limitedBusiness, $limitedStockManager)));
        $this->assertSame('executive', $resolver->resolve(DashboardContext::forUser($ownerBusiness, $owner)));
        $this->assertSame('branch_manager', $resolver->resolve(DashboardContext::forUser($adminBusiness, $admin)));
    }

    public function test_operational_roles_can_open_the_universal_dashboard_without_pos_permission(): void
    {
        foreach ([
            ['pre_seller', ['routes']],
            ['delivery_agent', ['routes']],
            ['stock_manager', ['inventory']],
            ['purchases', ['purchases']],
            ['reports', ['reports']],
            ['cashier', ['pos']],
        ] as [$role, $modules]) {
            [, $user] = $this->tenant($role, modules: $modules);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
        }
    }

    public function test_standard_operational_roles_resolve_to_their_safe_presentation_profiles(): void
    {
        $resolver = new DashboardProfileResolver();

        foreach ([
            ['pre_seller', ['routes'], 'route_seller'],
            ['delivery_agent', ['routes'], 'route_delivery'],
            ['cashier', ['pos'], 'cashier'],
            ['stock_manager', ['inventory'], 'warehouse'],
            ['purchases', ['purchases'], 'purchases'],
            ['reports', ['reports'], 'finance'],
        ] as [$role, $modules, $profile]) {
            [$business, $user] = $this->tenant($role, modules: $modules);

            $this->assertSame($profile, $resolver->resolve(DashboardContext::forUser($business, $user)));
        }
    }

    public function test_dashboard_actions_are_generated_only_for_authorized_routes(): void
    {
        [$business, $user] = $this->tenant('pre_seller', modules: ['routes']);
        $dashboard = app(DashboardService::class)->resolve(DashboardContext::forUser($business, $user));

        $this->assertSame('route_seller', $dashboard['profile']);
        $this->assertSame([], $dashboard['actions']);
        $this->assertSame([], app(DashboardService::class)->resolve(new DashboardContext(
            $business,
            $user,
            null,
            false,
            [],
            [],
            'America/Guatemala',
        ))['actions']);
    }

    public function test_dashboard_source_renders_actions_and_a_safe_empty_state(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString('dashboard.actions.map', $source);
        $this->assertStringContainsString('data-dashboard-action', $source);
        $this->assertStringContainsString('No hay acciones disponibles', $source);
    }

    public function test_professional_widgets_use_authoritative_sources_and_exclude_reversed_route_settlements(): void
    {
        [$business, $owner, $branch, $other] = $this->tenant('owner', branches: true, modules: ['pos', 'reports', 'inventory', 'credits', 'routes']);
        $business->tenantSetting()->update(['enable_credit_sales' => true]);
        $customer = $this->customer($business, 'CxC principal');
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'credit_limit' => 1000,
            'current_balance' => 125,
            'is_blocked' => false,
        ]);
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'branch_id' => $other->id,
            'customer_id' => $this->customer($business, 'CxC otra sucursal')->id,
            'credit_limit' => 1000,
            'current_balance' => 75,
            'is_blocked' => false,
        ]);
        StockReservation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $this->product($business, $branch, 'Reservado', 8, 2)->id,
            'source_type' => 'test',
            'source_id' => 1,
            'quantity' => 3,
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $context = new DashboardContext($business, $owner, $branch, true, [
            Permissions::INVENTORY_VIEW,
            Permissions::CREDITS_ACCOUNTS_VIEW,
            Permissions::ROUTES_VIEW,
            Permissions::ROUTES_PRE_SALES_ADMIN_VIEW,
            Permissions::ROUTES_DELIVERY_RUNS_VIEW,
        ], ['inventory', 'credits', 'routes'], 'America/Guatemala');
        $dashboard = app(DashboardService::class)->resolve($context, 'warehouse');

        $this->assertSame(['stock.alerts', 'stock.active_reservations'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertSame(1, $dashboard['widgets'][1]['data']['count']);

        $executive = app(DashboardService::class)->resolve($context, 'executive');
        $receivable = collect($executive['widgets'])->firstWhere('key', 'credits.receivable');
        $this->assertSame('200.00', $receivable['data']['amount']);
    }

    public function test_route_supervisor_uses_current_domain_states_and_excludes_reversed_settlement_cash(): void
    {
        [$business, $owner, $branch] = $this->tenant('owner', branches: true, modules: ['routes']);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'name' => 'Centro', 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $owner->id,
            'work_date' => today(),
            'status' => 'open',
            'started_at' => now(),
        ]);
        $customer = $this->customer($business, 'Cliente de ruta');
        RouteVisit::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'seller_id' => $owner->id,
            'status' => 'pending',
        ]);
        foreach ([PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING, PreSale::STATUS_PICKED, PreSale::STATUS_CANCELLED] as $status) {
            PreSale::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'route_work_day_id' => $workDay->id,
                'route_zone_id' => $zone->id,
                'customer_id' => $customer->id,
                'seller_id' => $owner->id,
                'status' => $status,
                'subtotal' => 10,
                'discount_total' => 0,
                'total' => 10,
            ]);
        }
        $this->settlement($business, $branch, $owner, 'confirmed', 40);
        $this->settlement($business, $branch, $owner, 'reversed', 60);

        $context = new DashboardContext($business, $owner, $branch, true, [
            Permissions::ROUTES_VIEW,
            Permissions::ROUTES_PRE_SALES_ADMIN_VIEW,
            Permissions::ROUTES_DELIVERY_RUNS_VIEW,
        ], ['routes'], 'America/Guatemala');
        $dashboard = app(DashboardService::class)->resolve($context);
        $widgets = collect($dashboard['widgets'])->keyBy('key');

        $this->assertSame('route_supervisor', $dashboard['profile']);
        $this->assertSame(1, $widgets['routes.open_work_days']['data']['count']);
        $this->assertSame(1, $widgets['routes.pending_visits']['data']['count']);
        $this->assertSame(3, $widgets['routes.operational_pre_sales']['data']['count']);
        $this->assertSame('40.00', $widgets['routes.effective_settlements']['data']['amount']);
        $this->assertSame(1, $widgets['routes.effective_settlements']['data']['count']);
    }

    private function tenant(string $role, bool $branches = false, array $modules = ['pos', 'inventory', 'cash_register']): array
    {
        $business = Business::query()->create([
            'name' => 'Dashboard '.uniqid(),
            'slug' => 'dashboard-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $main = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER', 'is_active' => true]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'use_branches' => $branches,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);
        foreach (array_unique([...$modules, ...($branches ? ['branches'] : [])]) as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }
        $user = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $main->id,
            'role' => $role,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        Permissions::assignRole($user, $role);

        if ($branches) {
            $user->branchAccesses()->create([
                'business_id' => $business->id,
                'branch_id' => $main->id,
            ]);
        }

        return $branches ? [$business, $user, $main, $other] : [$business, $user, $main];
    }

    private function product(Business $business, Branch $branch, string $name, int $stock, int $minimum): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'cost_price' => 10,
            'sale_price' => 50,
            'stock' => $stock,
            'min_stock' => $minimum,
            'is_active' => true,
        ]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]);

        return $product;
    }

    private function customer(Business $business, string $name): \App\Models\Customer
    {
        return \App\Models\Customer::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'country' => 'GT',
        ]);
    }

    private function settlement(Business $business, Branch $branch, User $actor, string $status, int $amount): RouteCashSettlement
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($business, $branch, $actor, $status, $amount) {
            $session = CashRegisterSession::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'opened_by' => $actor->id,
                'status' => 'open',
                'opening_amount' => 0,
                'expected_cash' => 0,
                'opened_at' => now(),
            ]);
            $movement = CashMovement::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'cash_register_session_id' => $session->id,
                'type' => 'route_cash_settlement',
                'amount' => $amount,
                'reference_type' => 'route_cash_settlement',
                'reference_id' => 0,
                'description' => 'Dashboard fixture',
                'created_by' => $actor->id,
            ]);
            $settlement = RouteCashSettlement::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'collector_user_id' => $actor->id,
                'received_by' => $actor->id,
                'recorded_by' => $actor->id,
                'confirmed_by' => $actor->id,
                'cash_register_session_id' => $session->id,
                'cash_movement_id' => $movement->id,
                'expected_amount' => $amount,
                'received_amount' => $amount,
                'difference_amount' => 0,
                'status' => $status,
                'confirmed_at' => now(),
            ]);
            $movement->update(['reference_id' => $settlement->id]);

            if ($status === 'reversed') {
                RouteCashSettlementReversal::query()->create([
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'route_cash_settlement_id' => $settlement->id,
                    'reason_code' => 'cash_not_received_by_branch',
                    'explanation' => 'Fixture de reversa',
                    'reversed_by' => $actor->id,
                    'reversed_at' => now(),
                    'cash_correction_type' => 'ledger_only',
                ]);
            }

            return $settlement;
        });
    }

    private function sale(Business $business, Branch $branch, User $user, Product $product, int $total, int $quantity, string $localDate = '2026-10-07 10:00:00', string $status = 'completed', string $timezone = 'America/Guatemala'): Sale
    {
        $at = Carbon::parse($localDate, $timezone)->utc();
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'business_number' => Sale::query()->where('business_id', $business->id)->count() + 1,
            'customer_name' => 'Cliente',
            'total' => $total,
            'payment_method' => 'cash',
            'document_type' => 'receipt',
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? $at : null,
            'created_by' => $user->id,
        ]);
        $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        SaleItem::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $total / $quantity,
            'unit_cost' => 10,
            'total_cost' => $quantity * 10,
            'profit_amount' => $total - ($quantity * 10),
            'total' => $total,
            'total_before_discount' => $total,
            'total_after_discount' => $total,
        ]);

        return $sale;
    }
}
