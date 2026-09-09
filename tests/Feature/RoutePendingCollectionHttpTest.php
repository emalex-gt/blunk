<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoutePendingCollectionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_delivery_agent_cannot_open_pending_collection_administration(): void
    {
        $business = Business::query()->create(['name' => 'Pending HTTP '.uniqid(), 'slug' => 'pending-http-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $agent = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $agent->roles()->attach(Role::query()->whereNull('business_id')->where('key', 'delivery_agent')->firstOrFail());

        $this->assertFalse(Permissions::userHas($agent->fresh(), Permissions::ROUTES_PENDING_COLLECTIONS_VIEW));
        $this->actingAs($agent)->get(route('routes.pending-collections.index'))->assertForbidden();
    }

    public function test_index_returns_the_applied_hybrid_queue_filters_and_pagination_contract(): void
    {
        $business = Business::query()->create(['name' => 'Pending filters '.uniqid(), 'slug' => 'pending-filters-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($user, [Permissions::ROUTES_PENDING_COLLECTIONS_VIEW]);

        $this->withSession(['active_business_id' => $business->id])->actingAs($user)
            ->get(route('routes.pending-collections.index', ['origin' => 'in_app_stop', 'aging' => '4_7', 'assigned_to' => 9, 'search' => 'Cliente 42']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PendingCollections/Index')
                ->where('filters.origin', 'in_app_stop')
                ->where('filters.aging', '4_7')
                ->where('filters.assigned_to', 9)
                ->where('filters.search', 'Cliente 42')
                ->has('pending_collections.data')
                ->has('pending_collections.links')
            );
    }
}
