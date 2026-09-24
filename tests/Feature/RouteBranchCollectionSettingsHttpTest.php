<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RouteBranchCollectionSettingsHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_superadmin_can_open_the_legacy_policy_for_the_branch_from_the_url_without_creating_a_row(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.tenants.branches.route-settings.index', [$business, $branch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Tenants/BranchRouteSettings')
                ->where('tenant.id', $business->id)
                ->where('branch.id', $branch->id)
                ->where('policy', null)
                ->has('payment_methods', 4));

        $this->assertDatabaseCount('route_branch_collection_settings', 0);
    }

    public function test_superadmin_updates_only_the_branch_selected_by_the_url(): void
    {
        [$business, $branchA] = $this->businessAndBranch();
        $branchB = $this->branch($business, 'Sucursal B');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branchB, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('super-admin.tenants.branches.route-settings.update', [$business, $branchA]), $this->payload())
            ->assertRedirect(route('super-admin.tenants.branches.route-settings.index', [$business, $branchA]))
            ->assertSessionHas('success', 'Configuración de rutas guardada.');

        $this->assertDatabaseHas('route_branch_collection_settings', [
            'branch_id' => $branchA->id,
            'collection_workflow_mode' => 'immediate_paid',
            'primary_payment_method' => 'cash',
        ]);
        $this->assertDatabaseHas('route_branch_collection_settings', [
            'branch_id' => $branchB->id,
            'collection_workflow_mode' => 'per_order_collection',
            'primary_payment_method' => 'transfer',
        ]);
    }

    public function test_superadmin_url_scope_ignores_the_users_active_branch(): void
    {
        [$business, $branchA] = $this->businessAndBranch();
        $branchB = $this->branch($business, 'Sucursal B');
        $superAdmin = $this->superAdmin(['current_branch_id' => $branchB->id]);

        $this->withSession(['active_business_id' => $business->id])
            ->actingAs($superAdmin)
            ->put(route('super-admin.tenants.branches.route-settings.update', [$business, $branchA]), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('route_branch_collection_settings', ['branch_id' => $branchA->id]);
        $this->assertDatabaseMissing('route_branch_collection_settings', ['branch_id' => $branchB->id]);
    }

    public function test_cross_tenant_branch_url_is_not_found_and_cannot_write(): void
    {
        [$businessA] = $this->businessAndBranch();
        [$businessB, $branchB] = $this->businessAndBranch();

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.tenants.branches.route-settings.index', [$businessA, $branchB]))
            ->assertNotFound();

        $this->actingAs($this->superAdmin())
            ->put(route('super-admin.tenants.branches.route-settings.update', [$businessA, $branchB]), $this->payload())
            ->assertNotFound();

        $this->assertDatabaseMissing('route_branch_collection_settings', ['branch_id' => $branchB->id]);
        $this->assertNotSame($businessA->id, $businessB->id);
    }

    public function test_tenant_operator_cannot_access_the_superadmin_branch_policy_routes(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        $operator = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $this->actingAs($operator)
            ->get(route('super-admin.tenants.branches.route-settings.index', [$business, $branch]))
            ->assertForbidden();
        $this->actingAs($operator)
            ->put(route('super-admin.tenants.branches.route-settings.update', [$business, $branch]), $this->payload())
            ->assertForbidden();
    }

    public function test_client_branch_policy_routes_no_longer_exist(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        $operator = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $this->withSession(['active_business_id' => $business->id])
            ->actingAs($operator)
            ->get('/routes/branch-collection-settings')
            ->assertNotFound();
        $this->withSession(['active_business_id' => $business->id])
            ->actingAs($operator)
            ->put('/routes/branch-collection-settings', $this->payload())
            ->assertNotFound();
    }

    public function test_superadmin_branch_and_client_navigation_markup_use_only_the_canonical_surface(): void
    {
        $branchMarkup = file_get_contents(resource_path('js/Pages/SuperAdmin/Tenants/Branches.tsx'));
        $settingsMarkup = file_get_contents(resource_path('js/Pages/SuperAdmin/Tenants/BranchRouteSettings.tsx'));
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString('Configuración de rutas', $branchMarkup);
        $this->assertStringContainsString('super-admin.tenants.branches.route-settings.index', $branchMarkup);
        $this->assertStringContainsString('SuperAdminLayout', $settingsMarkup);
        $this->assertStringContainsString('Documento de reparto + conciliación', $settingsMarkup);
        $this->assertStringNotContainsString("label: 'Configuración de rutas'", $layout);
        $this->assertStringNotContainsString('routes.branch-collection-settings', $layout);
    }

    /** @return array{collection_workflow_mode:string,allowed_payment_methods:array<int,string>,primary_payment_method:string} */
    private function payload(): array
    {
        return [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash', 'card'],
            'primary_payment_method' => 'cash',
        ];
    }

    /** @return array{Business, Branch} */
    private function businessAndBranch(): array
    {
        $business = Business::query()->create([
            'name' => 'Política HTTP '.uniqid(),
            'slug' => 'policy-http-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        TenantSetting::query()->create(['business_id' => $business->id]);

        return [$business, $this->branch($business, 'Sucursal A')];
    }

    private function branch(Business $business, string $name): Branch
    {
        return Branch::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => 'BR-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function superAdmin(array $attributes = []): User
    {
        return User::factory()->create([
            'business_id' => null,
            'current_branch_id' => null,
            'is_super_admin' => true,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
