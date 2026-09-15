<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteBranchCollectionSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
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
        Permissions::syncDefaults();
    }

    public function test_authorized_user_sees_legacy_policy_without_creating_a_row(): void
    {
        [$business, $branch, $actor] = $this->tenant('owner');

        $this->requestAs($actor, $business)->get(route('routes.branch-collection-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/BranchCollectionSettings/Index')
                ->where('branch.id', $branch->id)
                ->where('branch.name', $branch->name)
                ->where('policy', null)
                ->has('payment_methods', 4));

        $this->assertDatabaseCount('route_branch_collection_settings', 0);
    }

    public function test_existing_policy_is_returned_exactly_for_the_active_branch(): void
    {
        [$business, $branch, $actor] = $this->tenant('owner');
        $this->setting($branch, 'per_order_collection', ['cash', 'transfer'], 'transfer');

        $this->requestAs($actor, $business)->get(route('routes.branch-collection-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('policy.collection_workflow_mode', 'per_order_collection')
                ->where('policy.allowed_payment_methods', ['cash', 'transfer'])
                ->where('policy.primary_payment_method', 'transfer'));
    }

    public function test_authorized_update_creates_then_updates_the_same_active_branch_policy_with_flash_success(): void
    {
        [$business, $branch, $actor] = $this->tenant('owner');

        $this->requestAs($actor, $business)->put(route('routes.branch-collection-settings.update'), $this->payload())
            ->assertRedirect(route('routes.branch-collection-settings.index'))
            ->assertSessionHas('success', 'Configuración de rutas guardada.');

        $first = RouteBranchCollectionSetting::query()->where('branch_id', $branch->id)->firstOrFail();
        $this->assertSame('immediate_paid', $first->collection_workflow_mode);
        $this->assertSame(['cash', 'card'], $first->allowed_payment_methods);

        $this->requestAs($actor, $business)->put(route('routes.branch-collection-settings.update'), $this->payload([
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]))->assertRedirect(route('routes.branch-collection-settings.index'));

        $this->assertSame($first->id, RouteBranchCollectionSetting::query()->where('branch_id', $branch->id)->value('id'));
        $this->assertDatabaseCount('route_branch_collection_settings', 1);
    }

    public function test_update_rejects_invalid_manifest_and_payment_payloads_without_writing(): void
    {
        [$business, $branch, $actor] = $this->tenant('owner');

        foreach ([
            [...$this->payload(), 'collection_workflow_mode' => 'manifest_reconciliation'],
            [...$this->payload(), 'allowed_payment_methods' => []],
            [...$this->payload(), 'allowed_payment_methods' => ['cash', 'cash']],
            [...$this->payload(), 'allowed_payment_methods' => ['crypto']],
            [...$this->payload(), 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'card'],
        ] as $payload) {
            $this->requestAs($actor, $business)->put(route('routes.branch-collection-settings.update'), $payload)
                ->assertSessionHasErrors();
        }

        $this->assertDatabaseCount('route_branch_collection_settings', 0);
        $this->assertSame($branch->id, $actor->current_branch_id);
    }

    public function test_unauthorized_and_inactive_users_are_rejected_for_both_endpoints(): void
    {
        [$business, $branch] = $this->tenant('owner');
        $denied = $this->user($business, $branch, 'delivery_agent');
        $inactive = $this->user($business, $branch, 'owner', false);

        foreach ([$denied, $inactive] as $user) {
            $this->requestAs($user, $business)->get(route('routes.branch-collection-settings.index'))->assertForbidden();
            $this->requestAs($user, $business)->put(route('routes.branch-collection-settings.update'), $this->payload())->assertForbidden();
        }
    }

    public function test_active_branch_is_authoritative_and_frontend_branch_parameters_cannot_change_scope(): void
    {
        [$business, $activeBranch, $actor] = $this->tenant('owner');
        $otherBranch = $this->branch($business, 'Secundaria');

        $this->requestAs($actor, $business)->put(route('routes.branch-collection-settings.update'), [
            ...$this->payload(),
            'branch_id' => $otherBranch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('route_branch_collection_settings', ['branch_id' => $activeBranch->id]);
        $this->assertDatabaseMissing('route_branch_collection_settings', ['branch_id' => $otherBranch->id]);
    }

    public function test_request_cannot_cross_business_and_save_has_no_financial_side_effects(): void
    {
        [$business, $branch, $actor] = $this->tenant('owner');
        [$foreignBusiness, $foreignBranch] = $this->businessAndBranch();
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente '.uniqid(),
            'country' => 'GT',
        ]);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'seller_id' => $actor->id,
            'status' => PreSale::STATUS_DRAFT,
            'subtotal' => 25,
            'discount_total' => 0,
            'total' => 25,
        ]);

        $this->requestAs($actor, $business)->put(route('routes.branch-collection-settings.update'), [
            ...$this->payload(),
            'branch_id' => $foreignBranch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('route_branch_collection_settings', ['branch_id' => $branch->id]);
        $this->assertDatabaseMissing('route_branch_collection_settings', ['branch_id' => $foreignBranch->id]);
        $this->assertSame(PreSale::STATUS_DRAFT, $preSale->fresh()->status);
        $this->assertSame(0, \App\Models\Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, \App\Models\SalePayment::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame($business->id, $actor->business_id);
        $this->assertNotSame($foreignBusiness->id, $actor->business_id);
    }

    public function test_branch_collection_settings_markup_keeps_legacy_activation_and_only_allowed_primary_choices(): void
    {
        $markup = file_get_contents(resource_path('js/Pages/Routes/BranchCollectionSettings/Index.tsx'));

        $this->assertStringContainsString('Configuración heredada', $markup);
        $this->assertStringContainsString('Documento de reparto + conciliación', $markup);
        $this->assertStringContainsString('Próximamente', $markup);
        $this->assertStringContainsString('disabled', $markup);
        $this->assertStringContainsString("payment_methods.filter(method => form.data.allowed_payment_methods.includes(method.value))", $markup);
        $this->assertStringContainsString("form.data.allowed_payment_methods.length === 1", $markup);
        $this->assertStringContainsString('Guardar configuración', $markup);
    }

    public function test_navigation_exposes_the_settings_link_with_the_existing_routes_admin_capability_and_active_state(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString("label: 'Configuración de rutas'", $layout);
        $this->assertStringContainsString("can('routes.pre_sales.admin_view')", $layout);
        $this->assertStringContainsString("route('routes.branch-collection-settings.index')", $layout);
        $this->assertStringContainsString("route().current('routes.branch-collection-settings.*')", $layout);
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function requestAs(User $user, Business $business)
    {
        return $this->withSession(['active_business_id' => $business->id])->actingAs($user);
    }

    /** @return array{collection_workflow_mode:string,allowed_payment_methods:array<int,string>,primary_payment_method:string} */
    private function payload(array $overrides = []): array
    {
        return [...[
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash', 'card'],
            'primary_payment_method' => 'cash',
        ], ...$overrides];
    }

    /** @return array{Business,Branch,User} */
    private function tenant(string $role, bool $active = true): array
    {
        [$business, $branch] = $this->businessAndBranch();

        return [$business, $branch, $this->user($business, $branch, $role, $active)];
    }

    /** @return array{Business,Branch} */
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
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);

        return [$business, BranchInventory::defaultBranchForBusiness($business)];
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

    private function user(Business $business, Branch $branch, string $role, bool $active = true): User
    {
        $user = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
            'role' => $role,
            'is_active' => $active,
        ]);
        Permissions::assignRole($user, $role);

        return $user->fresh();
    }

    private function setting(Branch $branch, string $workflow, array $methods, string $primary): RouteBranchCollectionSetting
    {
        return RouteBranchCollectionSetting::query()->create([
            'branch_id' => $branch->id,
            'collection_workflow_mode' => $workflow,
            'allowed_payment_methods' => $methods,
            'primary_payment_method' => $primary,
        ]);
    }
}
