<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditAccount;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\Metrics\ExecutiveOverviewMetricQuery;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardExecutiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-31 15:00:00', 'America/Guatemala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_executive_profile_is_one_authorized_overview_without_duplicate_widgets(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $context = $this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']);

        $dashboard = app(DashboardService::class)->resolve($context, 'executive');

        $this->assertSame('executive', $dashboard['profile']);
        $this->assertSame(['executive.overview'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertSame('executive_overview', $dashboard['widgets'][0]['type']);
    }

    public function test_sales_comparisons_use_tenant_timezone_branch_scope_and_completed_sales_only(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant(withOtherBranch: true);
        $this->sale($business, $branch, $user, 120, '2026-03-31 10:00:00');
        $this->sale($business, $branch, $user, 80, '2026-03-30 10:00:00');
        $this->sale($business, $branch, $user, 900, '2026-03-31 11:00:00', 'cancelled');
        $this->sale($business, $otherBranch, $user, 700, '2026-03-31 10:00:00');
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant();
        $this->sale($foreign, $foreignBranch, $foreignUser, 5000, '2026-03-31 10:00:00');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']));

        $this->assertSame('120.00', $data['sales_today']['total']);
        $this->assertSame(1, $data['sales_today']['count']);
        $this->assertSame('120.00', $data['sales_today']['average_ticket']);
        $this->assertSame(50.0, $data['sales_today']['change_percent']);
    }

    public function test_zero_previous_sales_has_no_misleading_percentage_and_monthly_comparison_clamps(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $this->sale($business, $branch, $user, 100, '2026-03-31 10:00:00');
        $this->sale($business, $branch, $user, 200, '2026-02-28 10:00:00');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']));

        $this->assertNull($data['sales_today']['change_percent']);
        $this->assertSame('100.00', $data['sales_month']['total']);
        $this->assertSame('200.00', $data['sales_month']['previous_total']);
        $this->assertSame(-50.0, $data['sales_month']['change_percent']);
    }

    public function test_overview_exposes_financial_data_only_when_modules_permissions_and_effective_features_allow_it(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Crédito', 'country' => 'GT']);
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'credit_limit' => 1000,
            'current_balance' => 150,
            'is_blocked' => false,
        ]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'credits', 'is_enabled' => true, 'enabled_at' => now()]);

        $permissions = [Permissions::POS_VIEW, Permissions::CREDITS_ACCOUNTS_VIEW, Permissions::REPORTS_PROFIT_VIEW];
        $context = $this->context($business, $user, $branch, $permissions, ['pos', 'credits']);
        $disabled = $this->overview($context);

        $this->assertNull($disabled['receivable']);
        $this->assertNotNull($disabled['margin_today']);

        $business->tenantSetting()->update(['enable_credit_sales' => true]);
        $enabled = $this->overview($context);

        $this->assertSame('150.00', $enabled['receivable']['amount']);
        $this->assertNull($this->overview($this->context($business, $user, $branch, [], ['pos', 'credits']))['receivable']);
    }

    public function test_attention_is_deterministic_capped_and_contains_only_authorized_destinations(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Agotado',
            'code' => 'OUT-'.uniqid(),
            'cost_price' => 1,
            'sale_price' => 2,
            'stock' => 0,
            'min_stock' => 2,
            'is_active' => true,
        ]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 0]);

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));

        $this->assertLessThanOrEqual(7, $data['total_attention_count']);
        $this->assertSame('stock.out', $data['attention'][0]['key']);
        $this->assertSame('critical', $data['attention'][0]['severity']);
        $this->assertNull($data['attention'][0]['href']);
        $this->assertNull($this->overview($this->context($business, $user, $branch, [], ['inventory']))['stock']);
    }

    public function test_executive_frontend_renders_comparisons_attention_and_authorized_links_only(): void
    {
        $source = file_get_contents(resource_path('js/Components/Dashboard/ExecutiveDashboard.tsx'));
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString("widget.type === 'executive_overview'", $dashboard);
        $this->assertStringContainsString('sales_today.change_percent', $source);
        $this->assertStringContainsString('sales_month.change_percent', $source);
        $this->assertStringContainsString('attention.map', $source);
        $this->assertStringContainsString('item.href && <Link', $source);
        $this->assertStringContainsString('AreaChart', $source);
    }

    public function test_sales_trend_has_fourteen_tenant_and_branch_scoped_days_with_zero_fill(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant(withOtherBranch: true);
        $this->sale($business, $branch, $user, 125, '2026-03-25 10:00:00');
        $this->sale($business, $otherBranch, $user, 900, '2026-03-25 10:00:00');
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant();
        $this->sale($foreign, $foreignBranch, $foreignUser, 9000, '2026-03-25 10:00:00');

        $trend = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']))['sales_trend'];

        $this->assertCount(14, $trend);
        $this->assertSame('2026-03-25', $trend[7]['date']);
        $this->assertSame(125.0, $trend[7]['total']);
        $this->assertSame(1, $trend[7]['count']);
        $this->assertSame(0.0, $trend[0]['total']);
    }

    public function test_purchase_inventory_and_customer_blocks_require_their_specific_permissions(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Inventario', 'code' => 'INV-'.uniqid(), 'cost_price' => 12, 'sale_price' => 20, 'stock' => 3, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 3]);
        Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'status' => 'completed', 'total' => 45, 'payment_method' => 'cash', 'created_by' => $user->id]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente real', 'country' => 'GT']);
        $this->sale($business, $branch, $user, 80, '2026-03-31 10:00:00', 'completed', $customer);

        $without = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos', 'purchases', 'inventory']));
        $this->assertNull($without['purchases_month']);
        $this->assertNull($without['inventory_value']);
        $this->assertSame([], $without['top_customers']);

        $with = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW, Permissions::PURCHASES_VIEW, Permissions::REPORTS_WAREHOUSE_MONEY_VIEW, Permissions::REPORTS_SALES_BY_CUSTOMER_VIEW], ['pos', 'purchases', 'inventory']));
        $this->assertSame('45.00', $with['purchases_month']['total']);
        $this->assertSame('36.00', $with['inventory_value']['amount']);
        $this->assertSame('Cliente real', $with['top_customers'][0]['name']);
    }

    public function test_recent_activity_only_exposes_authorized_operation_types(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $sale = $this->sale($business, $branch, $user, 80, '2026-03-31 10:00:00');
        $purchase = Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'status' => 'completed', 'total' => 45, 'payment_method' => 'cash', 'created_by' => $user->id]);
        $purchase->forceFill(['created_at' => Carbon::parse('2026-03-31 11:00:00', 'America/Guatemala')->utc()])->save();

        $activity = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos', 'purchases']))['recent_activity'];
        $this->assertSame([], $activity);

        $activity = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW, Permissions::SALES_VIEW, Permissions::PURCHASES_VIEW], ['pos', 'purchases']))['recent_activity'];
        $this->assertSame(['purchase:'.$purchase->id, 'sale:'.$sale->id], collect($activity)->pluck('key')->all());
    }

    private function overview(DashboardContext $context): array
    {
        return (new ExecutiveOverviewMetricQuery())->resolve($context, $context->period('current'));
    }

    private function tenant(bool $withOtherBranch = false): array
    {
        $business = Business::query()->create([
            'name' => 'Executive '.uniqid(),
            'slug' => 'executive-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN-'.uniqid(), 'is_active' => true]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'use_branches' => $withOtherBranch,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);

        if (! $withOtherBranch) {
            return [$business, $user, $branch];
        }

        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER-'.uniqid(), 'is_active' => true]);

        return [$business, $user, $branch, $other];
    }

    private function context(Business $business, User $user, Branch $branch, array $permissions, array $modules): DashboardContext
    {
        return new DashboardContext($business, $user, $branch, true, $permissions, $modules, 'America/Guatemala');
    }

    private function sale(Business $business, Branch $branch, User $user, int $total, string $localDate, string $status = 'completed', ?Customer $customer = null): Sale
    {
        $at = Carbon::parse($localDate, 'America/Guatemala')->utc();
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'business_number' => Sale::query()->where('business_id', $business->id)->count() + 1,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name ?? 'Cliente ejecutivo',
            'total' => $total,
            'payment_method' => 'cash',
            'document_type' => 'receipt',
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? $at : null,
            'created_by' => $user->id,
        ]);
        $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $sale;
    }
}
