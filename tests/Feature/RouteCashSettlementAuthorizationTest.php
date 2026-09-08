<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\RouteCashSettlement;
use App\Models\Role;
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
        $branchB = \App\Models\Branch::query()->create(['business_id' => $businessA->id, 'name' => 'Otra '.uniqid(), 'code' => 'B-'.uniqid(), 'is_active' => true]);
        $otherBranchSettlement = $this->settlement($businessA, $branchB, $ownerA);
        [$businessB, $branchOther, $ownerB] = $this->userWithRole('owner');
        $foreignSettlement = $this->settlement($businessB, $branchOther, $ownerB);

        $this->actingAs($ownerA)->get(route('routes.cash-settlements.show', $otherBranchSettlement))->assertForbidden();
        $this->actingAs($ownerA)->get(route('routes.cash-settlements.show', $foreignSettlement))->assertForbidden();
        $this->actingAs($ownerA)->post(route('routes.cash-settlements.confirm', $foreignSettlement), [])->assertForbidden();
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: User} */
    private function userWithRole(string $role): array
    {
        $business = Business::query()->create(['name' => 'Auth '.uniqid(), 'slug' => 'auth-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        $user->roles()->attach(Role::query()->whereNull('business_id')->where('key', $role)->firstOrFail());
        return [$business, $branch, $user->fresh()];
    }

    private function settlement(Business $business, \App\Models\Branch $branch, User $collector): RouteCashSettlement
    {
        return RouteCashSettlement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'collector_user_id' => $collector->id, 'recorded_by' => $collector->id, 'status' => 'draft']);
    }
}
