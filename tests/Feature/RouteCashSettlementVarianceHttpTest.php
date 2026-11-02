<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\Role;
use App\Models\RouteCashSettlement;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementService;
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

    public function test_detail_requires_view_permission_even_when_manage_or_resolve_is_granted(): void
    {
        [$business, $branch, $viewer] = $this->user('owner');
        $variance = $this->variance($business, $branch);
        $manager = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $resolver = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($manager, [Permissions::ROUTES_CASH_VARIANCES_MANAGE]);
        Permissions::assignDirectPermissions($resolver, [Permissions::ROUTES_CASH_VARIANCES_RESOLVE]);

        $this->withSession(['active_business_id' => $business->id])->actingAs($manager)
            ->get(route('routes.cash-settlement-variances.show', $variance))
            ->assertForbidden();
        $this->withSession(['active_business_id' => $business->id])->actingAs($resolver)
            ->get(route('routes.cash-settlement-variances.show', $variance))
            ->assertForbidden();
        $this->withSession(['active_business_id' => $business->id])->actingAs($viewer)
            ->get(route('routes.cash-settlement-variances.show', $variance))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/CashSettlements/Variances/Show')
                ->where('variance.id', $variance->id));
    }

    public function test_viewer_cannot_open_a_variance_from_another_tenant(): void
    {
        [$business, $branch, $viewer] = $this->user('owner');
        [$foreignBusiness, $foreignBranch] = $this->businessWithBranch();
        $foreignVariance = $this->variance($foreignBusiness, $foreignBranch);

        $this->withSession(['active_business_id' => $business->id])->actingAs($viewer)
            ->get(route('routes.cash-settlement-variances.show', $foreignVariance))
            ->assertForbidden();
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

    /** @return array{0: Business, 1: \App\Models\Branch} */
    private function businessWithBranch(): array
    {
        $business = Business::query()->create(['name' => 'Variance detail '.uniqid(), 'slug' => 'variance-detail-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);

        return [$business, BranchInventory::defaultBranchForBusiness($business)];
    }

    private function variance(Business $business, \App\Models\Branch $branch): \App\Models\RouteCashSettlementVariance
    {
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'variance-http-draft-'.uniqid());
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 40, null, 'variance-http-confirm-'.uniqid(), [
            'reason_code' => 'missing_cash',
            'explanation' => 'Faltante físico de prueba.',
            'confirmed' => true,
        ]);

        return $settlement->fresh()->variance;
    }
}
