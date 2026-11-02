<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RoutePreSaleCollection;
use App\Models\Role;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteCashSettlementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_normal_delivery_agent_is_blocked_from_every_settlement_administration_endpoint(): void
    {
        [$business, $branch, $agent] = $this->userWithRole('delivery_agent');
        $settlement = $this->settlement($business, $branch, $agent);

        $this->assertFalse(Permissions::userHas($agent, Permissions::ROUTES_CASH_SETTLEMENTS_VIEW));
        $this->assertFalse(Permissions::userHas($agent, Permissions::ROUTES_CASH_SETTLEMENTS_CREATE));
        $this->assertFalse(Permissions::userHas($agent, Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM));
        $this->assertFalse(Permissions::userHas($agent, Permissions::ROUTES_CASH_SETTLEMENTS_REVIEW));

        $this->actingAs($agent)->get(route('routes.cash-settlements.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('routes.cash-settlements.show', $settlement))->assertForbidden();
        $this->actingAs($agent)->post(route('routes.cash-settlements.store'), [])->assertForbidden();
        $this->actingAs($agent)->post(route('routes.cash-settlements.items.store', $settlement), [])->assertForbidden();
        // Route-model binding rejects a non-existent item before the controller;
        // that still prevents discovery or removal through this endpoint.
        $this->actingAs($agent)->delete(route('routes.cash-settlements.items.destroy', [$settlement, 999]))->assertNotFound();
        $this->actingAs($agent)->post(route('routes.cash-settlements.cancel', $settlement), [])->assertForbidden();
        $this->actingAs($agent)->post(route('routes.cash-settlements.confirm', $settlement), [])->assertForbidden();
    }

    public function test_authorized_user_cannot_access_other_tenant_or_branch_settlement(): void
    {
        [$businessA, $branchA, $ownerA] = $this->userWithRole('owner');
        TenantSetting::query()->where('business_id', $businessA->id)->update(['use_branches' => true]);
        TenantModule::query()->create(['business_id' => $businessA->id, 'module' => 'branches', 'is_enabled' => true, 'enabled_at' => now()]);
        $ownerA->update(['all_branches_access' => false]);
        $ownerA->branchAccesses()->create(['business_id' => $businessA->id, 'branch_id' => $branchA->id]);
        $branchB = \App\Models\Branch::query()->create(['business_id' => $businessA->id, 'name' => 'Otra '.uniqid(), 'code' => 'B-'.uniqid(), 'is_active' => true]);
        $ownSettlement = $this->settlement($businessA, $branchA, $ownerA);
        $otherBranchSettlement = $this->settlement($businessA, $branchB, $ownerA);
        [$businessB, $branchOther, $ownerB] = $this->userWithRole('owner');
        $foreignSettlement = $this->settlement($businessB, $branchOther, $ownerB);

        $this->actingAs($ownerA)->get(route('routes.cash-settlements.show', $ownSettlement))->assertOk();
        $this->actingAs($ownerA)->get(route('routes.cash-settlements.show', $otherBranchSettlement))->assertForbidden();
        $this->actingAs($ownerA)->get(route('routes.cash-settlements.show', $foreignSettlement))->assertForbidden();
        $this->actingAs($ownerA)->post(route('routes.cash-settlements.confirm', $foreignSettlement), [])->assertForbidden();
    }

    public function test_viewer_and_reviewer_do_not_receive_eligible_collections_without_create_permission(): void
    {
        [$business, $branch] = $this->businessWithBranch();
        $collector = $this->activeUser($business, $branch);
        $eligible = $this->eligibleCollection($business, $branch, $collector);
        $settlement = $this->settlement($business, $branch, $collector);
        $viewer = $this->activeUser($business, $branch, [Permissions::ROUTES_CASH_SETTLEMENTS_VIEW]);
        $reviewer = $this->activeUser($business, $branch, [Permissions::ROUTES_CASH_SETTLEMENTS_REVIEW]);

        foreach ([$viewer, $reviewer] as $user) {
            $this->withSession(['active_business_id' => $business->id])->actingAs($user)
                ->get(route('routes.cash-settlements.index', ['collector_id' => $collector->id]))
                ->assertOk()
                ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                    ->component('Routes/CashSettlements/Index')
                    ->where('can_create', false)
                    ->where('eligible_collections', []));
            $this->withSession(['active_business_id' => $business->id])->actingAs($user)
                ->get(route('routes.cash-settlements.show', $settlement))
                ->assertOk()
                ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                    ->component('Routes/CashSettlements/Show')
                    ->where('eligible_collections', [])
                    ->where('receivers', []));
        }

        $this->assertDatabaseHas('route_pre_sale_collections', ['id' => $eligible->id, 'custody_status' => 'held_by_collector']);
    }

    public function test_create_permission_receives_eligible_collections_only_within_the_current_business_and_branch(): void
    {
        [$business, $branch] = $this->businessWithBranch();
        $collector = $this->activeUser($business, $branch);
        $eligible = $this->eligibleCollection($business, $branch, $collector);
        $settlement = $this->settlement($business, $branch, $collector);
        $creator = $this->activeUser($business, $branch, [
            Permissions::ROUTES_CASH_SETTLEMENTS_VIEW,
            Permissions::ROUTES_CASH_SETTLEMENTS_CREATE,
        ]);

        $this->withSession(['active_business_id' => $business->id])->actingAs($creator)
            ->get(route('routes.cash-settlements.index', ['collector_id' => $collector->id]))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Routes/CashSettlements/Index')
                ->where('can_create', true)
                ->has('eligible_collections', 1)
                ->where('eligible_collections.0.collection_id', $eligible->id));
        $this->withSession(['active_business_id' => $business->id])->actingAs($creator)
            ->get(route('routes.cash-settlements.show', $settlement))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('can_create', true)
                ->has('eligible_collections', 1)
                ->where('eligible_collections.0.collection_id', $eligible->id));
    }

    public function test_confirmer_can_read_a_draft_without_receiving_creation_collections(): void
    {
        [$business, $branch] = $this->businessWithBranch();
        $collector = $this->activeUser($business, $branch);
        $eligible = $this->eligibleCollection($business, $branch, $collector);
        $settlement = $this->settlement($business, $branch, $collector);
        $confirmer = $this->activeUser($business, $branch, [
            Permissions::ROUTES_CASH_SETTLEMENTS_VIEW,
            Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM,
        ]);

        $this->withSession(['active_business_id' => $business->id])->actingAs($confirmer)
            ->get(route('routes.cash-settlements.index', ['collector_id' => $collector->id]))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('can_create', false)
                ->where('can_confirm', true)
                ->where('eligible_collections', []));
        $this->withSession(['active_business_id' => $business->id])->actingAs($confirmer)
            ->get(route('routes.cash-settlements.show', $settlement))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('can_create', false)
                ->where('can_confirm', true)
                ->where('eligible_collections', [])
                ->has('receivers'));

        $this->assertDatabaseHas('route_pre_sale_collections', ['id' => $eligible->id, 'custody_status' => 'held_by_collector']);
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: User} */
    private function userWithRole(string $role): array
    {
        $business = Business::query()->create(['name' => 'Auth '.uniqid(), 'slug' => 'auth-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        $user->roles()->attach(Role::query()->whereNull('business_id')->where('key', $role)->firstOrFail());
        return [$business, $branch, $user->fresh()];
    }

    private function settlement(Business $business, \App\Models\Branch $branch, User $collector): RouteCashSettlement
    {
        return RouteCashSettlement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'collector_user_id' => $collector->id, 'recorded_by' => $collector->id, 'status' => 'draft']);
    }

    /** @return array{0: Business, 1: \App\Models\Branch} */
    private function businessWithBranch(): array
    {
        $business = Business::query()->create(['name' => 'Settlement visibility '.uniqid(), 'slug' => 'settlement-visibility-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);

        return [$business, BranchInventory::defaultBranchForBusiness($business)];
    }

    private function activeUser(Business $business, \App\Models\Branch $branch, array $permissions = []): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($user, $permissions);

        return $user->fresh();
    }

    private function eligibleCollection(Business $business, \App\Models\Branch $branch, User $collector): RoutePreSaleCollection
    {
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Eligible customer '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 25, 'discount_total' => 0, 'total' => 25]);

        return RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 25, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
    }
}
