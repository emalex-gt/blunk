<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardProfileResolver;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\Metrics\FinanceOverviewMetricQuery;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'America/Guatemala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reports_role_resolves_to_one_finance_overview_widget(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $context = $this->context($business, $user, $branch, [Permissions::REPORTS_SALES_VIEW], ['reports']);

        $this->assertSame('finance', app(DashboardProfileResolver::class)->resolve($context));
        $dashboard = app(DashboardService::class)->resolve($context, 'finance');
        $this->assertSame(['finance.overview'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertSame('finance_overview', $dashboard['widgets'][0]['type']);
    }

    public function test_sales_and_profit_use_tenant_branch_completed_sales_and_saved_snapshots(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant(withOtherBranch: true);
        $this->sale($business, $branch, $user, 100, '2026-10-20', null, 40, 60);
        $this->sale($business, $branch, $user, 999, '2026-10-20', null, 1, 998, 'cancelled');
        $this->sale($business, $otherBranch, $user, 800, '2026-10-20', null, 100, 700);
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant();
        $this->sale($foreign, $foreignBranch, $foreignUser, 9000, '2026-10-20', null, 1, 8999);

        $data = $this->data($this->context($business, $user, $branch, [Permissions::REPORTS_SALES_VIEW, Permissions::REPORTS_PROFIT_VIEW], ['reports']));

        $this->assertSame('100.00', $data['sales_month']['total']);
        $this->assertSame('40.00', $data['profit_month']['cost_total']);
        $this->assertSame('60.00', $data['profit_month']['profit_total']);
        $this->assertSame(60.0, $data['profit_month']['margin_percent']);
    }

    public function test_aging_uses_exact_boundaries_and_matches_real_open_credit_balances(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant(withOtherBranch: true);
        $customer = $this->customer($business, $branch, 'Deudor');
        foreach ([
            'current' => '2026-10-31', 'overdue_1_30_a' => '2026-10-30', 'overdue_1_30_b' => '2026-10-01',
            'overdue_31_60_a' => '2026-09-30', 'overdue_31_60_b' => '2026-09-01', 'overdue_61_90_a' => '2026-08-31',
            'overdue_61_90_b' => '2026-08-02', 'overdue_90_plus' => '2026-08-01', 'no_due_date' => null,
        ] as $key => $dueDate) $this->sale($business, $branch, $user, 10, '2026-10-20', $customer, 0, 0, 'completed', 10, $dueDate);
        $this->sale($business, $branch, $user, 10, '2026-10-20', $customer, 0, 0, 'completed', 0, '2026-10-01');
        $this->sale($business, $otherBranch, $user, 10, '2026-10-20', $customer, 0, 0, 'completed', 10, '2026-10-01');

        $data = $this->data($this->context($business, $user, $branch, [Permissions::CREDITS_ACCOUNTS_VIEW], ['credits'], true));

        $this->assertSame('90.00', $data['receivables']['total']);
        $this->assertSame(9, $data['receivables']['invoice_count']);
        $this->assertSame(1, $data['aging']['current']['count']);
        $this->assertSame(2, $data['aging']['overdue_1_30']['count']);
        $this->assertSame(2, $data['aging']['overdue_31_60']['count']);
        $this->assertSame(2, $data['aging']['overdue_61_90']['count']);
        $this->assertSame(1, $data['aging']['overdue_90_plus']['count']);
        $this->assertSame(1, $data['aging']['no_due_date']['count']);
        $this->assertSame('90.00', number_format(collect($data['aging'])->sum(fn ($bucket) => (float) $bucket['amount']), 2, '.', ''));
    }

    public function test_collections_exclude_cancelled_and_other_branch_and_financial_blocks_remain_permission_gated(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant(withOtherBranch: true);
        $customer = $this->customer($business, $branch, 'Cobros');
        $account = CustomerCreditAccount::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'current_balance' => 50]);
        foreach ([[50, 'completed', $branch], [60, 'cancelled', $branch], [70, 'completed', $otherBranch]] as [$amount, $status, $paymentBranch]) CustomerCreditPayment::query()->create(['business_id' => $business->id, 'branch_id' => $paymentBranch->id, 'customer_id' => $customer->id, 'customer_credit_account_id' => $account->id, 'payment_number' => CustomerCreditPayment::query()->count() + 1, 'amount' => $amount, 'payment_method' => 'bank_transfer', 'status' => $status, 'created_by' => $user->id]);

        $without = $this->data($this->context($business, $user, $branch, [], ['credits'], true));
        $this->assertNull($without['receivables']);
        $this->assertNull($without['aging']);
        $this->assertSame([], $without['top_debtors']);
        $this->assertSame([], $without['overdue_invoices']);
        $this->assertNull($without['collections_month']);
        $this->assertNull($without['profit_month']);
        $this->assertNull($without['inventory_value']);
        $this->assertNull($without['purchases_month']);

        $with = $this->data($this->context($business, $user, $branch, [Permissions::CREDITS_PAYMENTS_VIEW], ['credits'], true));
        $this->assertSame('50.00', $with['collections_month']['total']);
        $this->assertSame('Transferencia', $with['collections_mix'][0]['label']);
        $this->assertCount(1, $with['recent_collections']);
    }

    public function test_finance_frontend_is_delegated_from_dashboard(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));
        $finance = file_get_contents(resource_path('js/Components/Dashboard/FinanceDashboard.tsx'));

        $this->assertStringContainsString("widget.type === 'finance_overview'", $dashboard);
        $this->assertStringContainsString('ComposedChart', $finance);
        $this->assertStringContainsString('Aging de cartera', $finance);
    }

    private function data(DashboardContext $context): array
    {
        return (new FinanceOverviewMetricQuery())->resolve($context, $context->period('current'));
    }

    private function tenant(bool $withOtherBranch = false): array
    {
        $business = Business::query()->create(['name' => 'Finance '.uniqid(), 'slug' => 'finance-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'FIN-'.uniqid(), 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_product_images' => true, 'use_branches' => $withOtherBranch, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false, 'enable_credit_sales' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'credits', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'reports', 'is_active' => true]);
        if (! $withOtherBranch) return [$business, $user, $branch];
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER-'.uniqid(), 'is_active' => true]);
        return [$business, $user, $branch, $other];
    }

    private function context(Business $business, User $user, Branch $branch, array $permissions, array $modules, bool $creditSales = false): DashboardContext
    {
        if ($creditSales) $business->tenantSetting->update(['enable_credit_sales' => true]);
        return new DashboardContext($business, $user, $branch, true, $permissions, $modules, 'America/Guatemala');
    }

    private function customer(Business $business, Branch $branch, string $name): Customer
    {
        return Customer::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'name' => $name, 'country' => 'GT']);
    }

    private function sale(Business $business, Branch $branch, User $user, float $total, string $localDate, ?Customer $customer = null, float $cost = 0, float $profit = 0, string $status = 'completed', float $creditBalance = 0, ?string $dueDate = null): Sale
    {
        $at = Carbon::parse($localDate.' 10:00:00', 'America/Guatemala')->utc();
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => Sale::query()->where('business_id', $business->id)->count() + 1, 'customer_id' => $customer?->id, 'customer_name' => $customer?->name ?? 'Consumidor Final', 'total' => $total, 'payment_method' => 'credit', 'document_type' => 'receipt', 'status' => $status, 'is_credit_sale' => $creditBalance > 0, 'credit_balance' => $creditBalance, 'due_date' => $dueDate, 'created_by' => $user->id]);
        $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        SaleItem::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'product_name' => 'Producto', 'quantity' => 1, 'unit_price' => $total, 'unit_cost' => $cost, 'total_cost' => $cost, 'profit_amount' => $profit, 'total' => $total, 'total_before_discount' => $total, 'total_after_discount' => $total]);
        return $sale;
    }
}
