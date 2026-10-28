<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\OperationDraft;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardCashierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_cashier_gets_only_the_personal_workspace_without_global_financial_widgets_or_actions(): void
    {
        [, $cashier] = $this->cashier();

        $this->actingAs($cashier)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.profile', 'cashier')
            ->where('dashboard.widgets', fn ($widgets) => collect($widgets)->pluck('key')->all() === ['cashier.workspace'])
            ->where('dashboard.actions', fn ($actions) => collect($actions)->pluck('key')->all() === ['sales.create.cashier'])
            ->where('dashboard.widgets.0.data.cash_session', null));
    }

    public function test_open_branch_cash_session_uses_only_the_cashiers_sales_since_opening(): void
    {
        [$business, $cashier, $branch] = $this->cashier();
        Permissions::assignDirectPermissions($cashier, [Permissions::CASH_REGISTER_VIEW]);
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $cashier->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()->subHour()]);
        $sale = $this->sale($business, $branch, $cashier, 100, now()->subMinutes(20));
        $other = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $this->sale($business, $branch, $other, 500, now()->subMinutes(10));
        $this->sale($business, $branch, $cashier, 200, now()->subHours(2));
        $this->sale($business, $branch, $cashier, 90, now()->subMinutes(5), 'cancelled');
        $this->payment($business, $sale, $cashier, 'cash', 60, now()->subMinutes(15));
        $this->payment($business, $sale, $cashier, 'card', 40, now()->subMinutes(10));
        $this->payment($business, $sale, $other, 'cash', 99, now()->subMinutes(5));
        CashMovement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'cash_register_session_id' => $session->id, 'type' => 'sale_cash', 'amount' => 60, 'description' => 'Venta en efectivo', 'created_by' => $cashier->id]);

        $this->actingAs($cashier)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.widgets.0.data.cash_session.status', 'open')
            ->where('dashboard.widgets.0.data.sales_period', 'current_session')
            ->where('dashboard.widgets.0.data.sales_today.count', 1)
            ->where('dashboard.widgets.0.data.sales_today.total', '100.00')
            ->where('dashboard.widgets.0.data.sales_today.average_ticket', '100.00')
            ->where('dashboard.widgets.0.data.collections_today.total', '100.00')
            ->where('dashboard.widgets.0.data.payment_mix.0.label', 'Efectivo')
            ->where('dashboard.widgets.0.data.recent_activity', fn ($rows) => collect($rows)->pluck('type')->contains('cash')));
    }

    public function test_closed_cash_uses_personal_today_sales_and_excludes_wrong_branch_and_tenant(): void
    {
        [$business, $cashier, $branch] = $this->cashier();
        $this->sale($business, $branch, $cashier, 75, now()->subHour());
        $otherBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER', 'is_active' => true]);
        $this->sale($business, $otherBranch, $cashier, 900, now()->subHour());
        [$foreignBusiness, $foreignCashier, $foreignBranch] = $this->cashier();
        $this->sale($foreignBusiness, $foreignBranch, $foreignCashier, 900, now()->subHour());

        $this->actingAs($cashier)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.widgets.0.data.sales_period', 'today')
            ->where('dashboard.widgets.0.data.sales_today.count', 1)
            ->where('dashboard.widgets.0.data.sales_today.total', '75.00'));
    }

    public function test_active_pos_drafts_are_personal_branch_scoped_and_type_specific(): void
    {
        [$business, $cashier, $branch] = $this->cashier();
        $this->draft($business, $branch, $cashier, 'pos_sale', 'active');
        $this->draft($business, $branch, $cashier, 'purchase', 'active');
        $this->draft($business, $branch, $cashier, 'pos_sale', 'converted');
        $other = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $this->draft($business, $branch, $other, 'pos_sale', 'active');

        $this->actingAs($cashier)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.widgets.0.data.pending.active_pos_drafts_count', 1));
    }

    public function test_cashier_workspace_source_renders_safe_open_and_closed_states(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));
        $component = file_get_contents(resource_path('js/Components/Dashboard/CashierDashboard.tsx'));
        $this->assertStringContainsString("widget.type === 'cashier_workspace'", $source);
        $this->assertStringContainsString('CashierDashboardData', $source);
        $this->assertStringNotContainsString('CashierWorkspaceCard', $source);
        $this->assertStringContainsString('Formas de cobro', $component);
        $this->assertStringContainsString('Actividad reciente', $component);
    }

    private function cashier(): array
    {
        $business = Business::query()->create(['name' => 'Dashboard cashier '.uniqid(), 'slug' => 'dashboard-cashier-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'use_product_images' => true, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false]);
        foreach (['pos', 'cash_register'] as $module) TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        $cashier = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'cashier', 'all_branches_access' => false, 'is_active' => true]);
        Permissions::assignRole($cashier, 'cashier');
        $cashier->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]);
        return [$business, $cashier, $branch];
    }

    private function sale(Business $business, Branch $branch, User $user, int $total, $at, string $status = 'completed'): Sale
    {
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => Sale::query()->where('business_id', $business->id)->count() + 1, 'customer_name' => 'Cliente', 'total' => $total, 'payment_method' => 'cash', 'document_type' => 'receipt', 'status' => $status, 'created_by' => $user->id]);
        $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        return $sale;
    }

    private function draft(Business $business, Branch $branch, User $user, string $type, string $status): void
    {
        OperationDraft::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'type' => $type, 'payload' => [], 'status' => $status]);
    }

    private function payment(Business $business, Sale $sale, User $user, string $method, int $amount, $at): void
    {
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => $method, 'amount' => $amount, 'collected_by' => $user->id, 'collected_at' => $at, 'status' => 'captured']);
    }
}
