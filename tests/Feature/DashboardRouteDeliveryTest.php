<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\RouteDeliveryRun;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardRouteDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_delivery_agent_without_a_current_run_gets_the_personal_safe_state(): void
    {
        [, $agent] = $this->deliveryAgent();

        $this->actingAs($agent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.profile', 'route_delivery')
            ->where('dashboard.widgets.0.key', 'routes.my_delivery_run')
            ->where('dashboard.widgets.0.data.has_current_run', false)
            ->where('dashboard.widgets.0.data.primary_action.label', 'Ver mis entregas')
            ->where('dashboard.widgets.0.data.primary_action.href', route('routes.delivery-runs.index', absolute: false)));
    }

    public function test_open_assigned_run_is_selected_with_its_exact_continue_url(): void
    {
        [$business, $agent, $branch] = $this->deliveryAgent();
        $run = $this->deliveryRun($business, $branch, $agent, 'open', now()->subHour());

        $this->actingAs($agent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.widgets.0.data.run.id', $run->id)
            ->where('dashboard.widgets.0.data.run.status', 'open')
            ->where('dashboard.widgets.0.data.primary_action.label', 'Continuar entregas')
            ->where('dashboard.widgets.0.data.primary_action.href', route('routes.delivery-runs.show', $run, absolute: false))
            ->where('dashboard.widgets.0.data.progress.assigned', 0)
            ->where('dashboard.widgets.0.data.next_stop', null));
    }

    public function test_draft_is_selected_when_no_open_assigned_run_exists(): void
    {
        [$business, $agent, $branch] = $this->deliveryAgent();
        $run = $this->deliveryRun($business, $branch, $agent, 'draft');

        $this->actingAs($agent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.widgets.0.data.run.id', $run->id)
            ->where('dashboard.widgets.0.data.run.status', 'draft')
            ->where('dashboard.widgets.0.data.primary_action.label', 'Ver jornada')
            ->where('dashboard.widgets.0.data.primary_action.href', route('routes.delivery-runs.show', $run, absolute: false)));
    }

    public function test_personal_scope_ignores_other_users_branches_tenants_and_closed_runs(): void
    {
        [$business, $agent, $branch] = $this->deliveryAgent();
        $otherAgent = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $this->deliveryRun($business, $branch, $otherAgent, 'open', now()->subHours(3));
        $this->deliveryRun($business, $branch, $agent, 'closed', now()->subHours(2));
        $otherBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER', 'is_active' => true]);
        $this->deliveryRun($business, $otherBranch, $agent, 'open', now()->subHour());
        [$foreignBusiness, $foreignAgent, $foreignBranch] = $this->deliveryAgent();
        $this->deliveryRun($foreignBusiness, $foreignBranch, $foreignAgent, 'open', now());

        $this->actingAs($agent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.widgets.0.data.has_current_run', false));
    }

    public function test_route_delivery_dashboard_has_no_global_financial_widgets_or_duplicate_quick_action(): void
    {
        [, $agent] = $this->deliveryAgent();

        $this->actingAs($agent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.widgets', fn ($widgets) => collect($widgets)->pluck('key')->all() === ['routes.my_delivery_run'])
            ->where('dashboard.actions', []));
    }

    public function test_dashboard_source_renders_delivery_progress_next_stop_and_safe_empty_states(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));
        $source = file_get_contents(resource_path('js/Components/Dashboard/RouteDeliveryDashboard.tsx'));

        $this->assertStringContainsString("widget.type === 'route_delivery_run'", $dashboard);
        $this->assertStringContainsString('RouteDeliveryDashboard', $dashboard);
        $this->assertStringContainsString("label' => \$isOpen ? 'Continuar entregas'", file_get_contents(app_path('Services/Dashboard/Metrics/RouteDeliveryRunMetricQuery.php')));
        $this->assertStringContainsString('Todas las entregas están resueltas.', $source);
        $this->assertStringContainsString('Próxima entrega', $source);
        $this->assertStringContainsString('Cobros pendientes', $source);
        $this->assertStringContainsString('role="progressbar"', $source);
        $this->assertStringContainsString('No tienes una jornada asignada', $source);
    }

    private function deliveryAgent(): array
    {
        $business = Business::query()->create(['name' => 'Dashboard delivery '.uniqid(), 'slug' => 'dashboard-delivery-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'use_product_images' => true, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $agent = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'delivery_agent', 'all_branches_access' => false, 'is_active' => true]);
        Permissions::assignRole($agent, 'delivery_agent');
        $agent->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]);

        return [$business, $agent, $branch];
    }

    private function deliveryRun(Business $business, Branch $branch, User $agent, string $status, $startedAt = null): RouteDeliveryRun
    {
        return RouteDeliveryRun::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'delivery_user_id' => $agent->id,
            'created_by' => $agent->id,
            'started_by' => $status === 'open' ? $agent->id : null,
            'started_at' => $startedAt,
            'closed_by' => $status === 'closed' ? $agent->id : null,
            'closed_at' => $status === 'closed' ? now() : null,
            'status' => $status,
            'delivery_tracking_snapshot' => 'in_app',
            'collection_responsibility_snapshot' => 'delivery_agent',
        ]);
    }
}
