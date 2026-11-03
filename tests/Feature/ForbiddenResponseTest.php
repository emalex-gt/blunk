<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Business;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Inertia\Inertia;
use Tests\TestCase;

class ForbiddenResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->withoutVite(); Permissions::syncDefaults(); }

    public function test_forbidden_web_and_inertia_responses_are_safe(): void
    {
        [$business, $user] = $this->fixture([]);
        $this->as($user, $business)->get(route('brands.index'))->assertStatus(403)->assertInertia(fn (Assert $page) => $page->component('Errors/Forbidden')->where('message', 'No tienes permiso para acceder a esta sección.'));
        $this->as($user, $business)->get(route('brands.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
            ->assertStatus(403)->assertHeader('X-Inertia', 'true')
            ->assertJsonStructure(['component', 'props' => ['message'], 'url', 'version'])
            ->assertJsonPath('component', 'Errors/Forbidden')
            ->assertJsonPath('props.message', 'No tienes permiso para acceder a esta sección.');
    }

    public function test_inertia_asset_version_mismatch_remains_a_409(): void
    {
        [$business, $user] = $this->fixture([Permissions::BRANDS_VIEW]);
        $this->as($user, $business)->get(route('brands.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => 'outdated'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location');
    }

    public function test_forbidden_json_and_post_do_not_leak_or_write(): void
    {
        [$business, $user] = $this->fixture([]);
        $this->as($user, $business)->getJson(route('brands.index'))->assertStatus(403)->assertJson(['message' => 'No tienes permiso para realizar esta acción.']);
        $this->as($user, $business)->post(route('brands.store'), ['name' => 'Bloqueada'])->assertStatus(403);
        $this->assertDatabaseMissing('brands', ['business_id' => $business->id, 'name' => 'Bloqueada']);
    }

    public function test_authorized_user_remains_authorized(): void
    {
        [$business, $user] = $this->fixture([Permissions::BRANDS_VIEW]);
        $this->as($user, $business)->get(route('brands.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Brands/Index'));
    }

    public function test_guest_redirect_remains_unchanged(): void
    {
        $this->get(route('brands.index'))->assertRedirect(route('login'));
    }

    public function test_inactive_user_keeps_tenant_blocked_response(): void
    {
        [$business, $user] = $this->fixture([Permissions::BRANDS_VIEW]);
        $user->update(['is_active' => false]);
        $this->as($user->fresh(), $business)->get(route('brands.index'))->assertStatus(403)->assertInertia(fn (Assert $page) => $page->component('TenantBlocked'));
    }

    public function test_forbidden_response_is_safe_for_a_multibranch_user_without_any_branch_access(): void
    {
        [$business, $user] = $this->fixture([]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        BranchInventory::defaultBranchForBusiness($business);
        $user->forceFill(['all_branches_access' => false, 'current_branch_id' => null])->save();

        $this->as($user->fresh(), $business)
            ->get(route('brands.index'))
            ->assertStatus(403)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/Forbidden')
                ->where('active_branch', null)
                ->where('branches', [])
                ->where('navigation_brand.branch_name', null));

        $this->assertDatabaseMissing('user_branch_accesses', ['user_id' => $user->id, 'business_id' => $business->id]);
        $this->assertNull($user->fresh()->current_branch_id);
    }

    public function test_authorized_multibranch_user_keeps_active_branch_props(): void
    {
        [$business, $user] = $this->fixture([Permissions::BRANDS_VIEW]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]);
        $user->forceFill(['all_branches_access' => false, 'current_branch_id' => $branch->id])->save();

        $this->as($user->fresh(), $business)
            ->get(route('brands.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Brands/Index')
                ->where('active_branch.id', $branch->id)
                ->where('navigation_brand.branch_name', $branch->name)
                ->has('branches', 1)
                ->where('branches.0.id', $branch->id));
    }

    public function test_not_found_and_validation_errors_keep_their_statuses(): void
    {
        $this->get('/not-a-real-route')->assertNotFound();
        [$business, $user] = $this->fixture([Permissions::BRANDS_VIEW, Permissions::BRANDS_CREATE]);
        $this->as($user, $business)->postJson(route('brands.store'), [])->assertStatus(422);
    }

    private function fixture(array $permissions): array
    {
        $business = Business::query()->create(['name' => 'Forbidden', 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'inventory', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($user, $permissions);
        return [$business, $user];
    }

    private function as(User $user, Business $business): static
    {
        return $this->actingAs($user)->withSession(['current_business_id' => $business->id, 'active_business_id' => $business->id]);
    }
}
