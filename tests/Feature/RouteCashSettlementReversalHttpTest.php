<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\Role;
use App\Models\RouteCashSettlement;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RouteCashSettlementReversalHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_authorized_user_reverses_a_confirmed_settlement_and_detail_exposes_history(): void
    {
        [$business, $actor, $settlement] = $this->confirmedSettlement('owner');

        $this->withSession(['active_business_id' => $business->id])->actingAs($actor)
            ->post(route('routes.cash-settlements.reverse', $settlement), [
                'idempotency_key' => 'http-settlement-reversal-0001',
                'explanation' => 'El efectivo nunca llegó a la sucursal.',
                'confirm_cash_adjustment' => true,
            ])->assertRedirect(route('routes.cash-settlements.show', $settlement));

        $this->assertSame('reversed', $settlement->fresh()->status);
        $this->assertDatabaseCount('route_cash_settlement_reversals', 1);
        $this->withSession(['active_business_id' => $business->id])->actingAs($actor)
            ->post(route('routes.cash-settlements.reverse', $settlement), [
                'idempotency_key' => 'http-settlement-reversal-0001',
                'explanation' => 'El efectivo nunca llegó a la sucursal.',
                'confirm_cash_adjustment' => true,
            ])->assertRedirect(route('routes.cash-settlements.show', $settlement));
        $this->assertDatabaseCount('route_cash_settlement_reversals', 1);
        $this->withSession(['active_business_id' => $business->id])->actingAs($actor)
            ->get(route('routes.cash-settlements.show', $settlement))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settlement.status', 'reversed')
                ->where('settlement.reversal.reason_code', 'cash_not_received_by_branch')
                ->where('can_reverse', true)
                ->where('reversal_eligibility.eligible', false));
    }

    public function test_user_without_reverse_permission_is_forbidden(): void
    {
        [$business, $owner, $settlement] = $this->confirmedSettlement('owner');
        $agent = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $owner->current_branch_id, 'role' => 'delivery_agent', 'is_active' => true]);
        $agent->roles()->attach(Role::query()->whereNull('business_id')->where('key', 'delivery_agent')->firstOrFail());

        $this->withSession(['active_business_id' => $business->id])->actingAs($agent)
            ->post(route('routes.cash-settlements.reverse', $settlement), [
                'idempotency_key' => 'http-settlement-reversal-denied',
                'explanation' => 'No autorizado.',
                'confirm_cash_adjustment' => true,
            ])->assertForbidden();
    }

    public function test_open_current_session_requires_explicit_cash_adjustment_confirmation(): void
    {
        [$business, $actor, $settlement] = $this->confirmedSettlement('owner');

        $this->withSession(['active_business_id' => $business->id])->actingAs($actor)
            ->from(route('routes.cash-settlements.show', $settlement))
            ->post(route('routes.cash-settlements.reverse', $settlement), [
                'idempotency_key' => 'http-settlement-reversal-confirm',
                'explanation' => 'Sin confirmación de ajuste.',
            ])->assertRedirect(route('routes.cash-settlements.show', $settlement))
            ->assertSessionHasErrors('confirm_cash_adjustment');

        $this->assertSame('confirmed', $settlement->fresh()->status);
    }

    public function test_settlement_from_another_business_is_forbidden_even_for_an_owner(): void
    {
        [$business, , $settlement] = $this->confirmedSettlement('owner');
        $other = Business::query()->create(['name' => 'Other '.uniqid(), 'slug' => 'other-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $other->id]);
        TenantModule::query()->create(['business_id' => $other->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $branch = BranchInventory::defaultBranchForBusiness($other);
        $actor = User::factory()->create(['business_id' => $other->id, 'current_branch_id' => $branch->id, 'role' => 'owner', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->whereNull('business_id')->where('key', 'owner')->firstOrFail());

        $this->withSession(['active_business_id' => $other->id])->actingAs($actor)
            ->post(route('routes.cash-settlements.reverse', $settlement), [
                'idempotency_key' => 'http-settlement-reversal-other',
                'explanation' => 'No debe alcanzar otra empresa.',
            ])->assertForbidden();
        $this->assertSame($business->id, $settlement->business_id);
    }

    /** @return array{0: Business, 1: User, 2: RouteCashSettlement} */
    private function confirmedSettlement(string $role): array
    {
        $business = Business::query()->create(['name' => 'Settlement HTTP '.uniqid(), 'slug' => 'settlement-http-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        $actor->roles()->attach(Role::query()->whereNull('business_id')->where('key', $role)->firstOrFail());
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'http-settlement-draft-'.uniqid());
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'http-settlement-confirm-'.uniqid());

        return [$business, $actor->fresh(), $settlement->fresh()];
    }
}
