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

class RouteCashSettlementVarianceHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_view_permission_receives_inertia_queue_summary_and_delivery_agent_is_denied(): void
    {
        [$business, $branch, $viewer] = $this->user('owner');
        [, , $agent] = $this->user('delivery_agent');

        $this->withSession(['active_business_id' => $business->id])->actingAs($viewer)->get(route('routes.cash-settlement-variances.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/CashSettlements/Variances/Index')
                ->has('summary.open_shortages.count')
                ->has('summary.open_overages.amount')
                ->has('variances.data'));
        $this->actingAs($agent)->get(route('routes.cash-settlement-variances.index'))->assertForbidden();
        $this->assertFalse(Permissions::userHas($agent, Permissions::ROUTES_CASH_VARIANCES_VIEW));
        $this->assertSame($business->id, $viewer->business_id);
        $this->assertSame($branch->id, $viewer->current_branch_id);
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: User} */
    private function user(string $role): array
    {
        $business = Business::query()->create(['name' => 'Variance HTTP '.uniqid(), 'slug' => 'variance-http-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        $user->roles()->attach(Role::query()->whereNull('business_id')->where('key', $role)->firstOrFail());
        if ($role === 'owner') {
            Permissions::assignDirectPermissions($user, [
                Permissions::ROUTES_CASH_VARIANCES_VIEW,
                Permissions::ROUTES_CASH_VARIANCES_MANAGE,
                Permissions::ROUTES_CASH_VARIANCES_RESOLVE,
            ]);
        }
        return [$business, $branch, $user->fresh()];
    }
}
