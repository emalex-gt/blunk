<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoutePreSaleCollectionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_seller_can_capture_a_full_collection_and_replay_the_same_request(): void
    {
        [$business, $branch, $seller, $preSale] = $this->preSale();
        $this->openCashRegister($business, $branch, $seller);
        $payload = $this->payload($preSale, 'collection-http-idempotent');

        $this->actingAs($seller)->post(route('routes.pre-sales.collection.store', $preSale), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($seller)->post(route('routes.pre-sales.collection.store', $preSale), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, RoutePreSaleCollection::query()->where('pre_sale_id', $preSale->id)->count());
        $this->assertDatabaseHas('route_pre_sale_collections', [
            'pre_sale_id' => $preSale->id,
            'amount' => 125,
            'payment_method' => 'transfer',
            'collected_by' => $seller->id,
            'recorded_by' => $seller->id,
        ]);
    }

    public function test_other_user_cannot_register_an_override_without_permission(): void
    {
        [$business, $branch, $seller, $preSale] = $this->preSale();
        $operator = $this->user($business, $branch, 'pre_seller');
        $this->openCashRegister($business, $branch, $operator);

        $this->actingAs($operator)->post(route('routes.pre-sales.collection.store', $preSale), [
            ...$this->payload($preSale, 'collection-no-override'),
            'collected_by' => $seller->id,
            'collected_at' => now()->subMinute()->toDateTimeString(),
            'override_reason' => 'Corrección',
        ])->assertForbidden();

        $this->assertDatabaseCount('route_pre_sale_collections', 0);
    }

    public function test_authorized_override_requires_collector_collection_time_and_reason(): void
    {
        [$business, $branch, $seller, $preSale] = $this->preSale();
        $operator = $this->user($business, $branch, 'pre_seller');
        Permissions::assignDirectPermissions($operator, [Permissions::ROUTES_COLLECTIONS_OVERRIDE]);
        $this->openCashRegister($business, $branch, $operator);

        foreach (['collected_by', 'collected_at', 'override_reason'] as $missing) {
            $payload = [
                ...$this->payload($preSale, 'collection-override-missing-'.$missing),
                'collected_by' => $seller->id,
                'collected_at' => now()->subMinute()->toDateTimeString(),
                'override_reason' => 'El cobrador entregó la constancia después del corte.',
            ];
            unset($payload[$missing]);

            $this->actingAs($operator)->post(route('routes.pre-sales.collection.store', $preSale), $payload)
                ->assertSessionHasErrors($missing);
        }

        $this->actingAs($operator)->post(route('routes.pre-sales.collection.store', $preSale), [
            ...$this->payload($preSale, 'collection-authorized-override'),
            'collected_by' => $seller->id,
            'collected_at' => now()->subMinute()->toDateTimeString(),
            'override_reason' => 'El cobrador entregó la constancia después del corte.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('route_pre_sale_collections', [
            'pre_sale_id' => $preSale->id,
            'collected_by' => $seller->id,
            'recorded_by' => $operator->id,
            'override_reason' => 'El cobrador entregó la constancia después del corte.',
        ]);
    }

    public function test_collection_endpoint_is_isolated_by_tenant_and_active_branch(): void
    {
        [$business, $branch, $seller] = $this->preSale();
        [$otherBusiness, $otherBranch, $otherSeller, $otherPreSale] = $this->preSale();
        $this->openCashRegister($otherBusiness, $otherBranch, $otherSeller);

        $this->actingAs($seller)->post(route('routes.pre-sales.collection.store', $otherPreSale), $this->payload($otherPreSale, 'collection-other-tenant'))
            ->assertForbidden();

        $otherBranchInBusiness = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Otra sucursal '.uniqid(),
            'code' => 'OTHER-'.uniqid(),
            'is_active' => true,
        ]);
        $otherBranchPreSale = $this->preSale($business, $otherBranchInBusiness, $seller)[3];
        $this->openCashRegister($business, $otherBranchInBusiness, $seller);

        $this->actingAs($seller)->post(route('routes.pre-sales.collection.store', $otherBranchPreSale), $this->payload($otherBranchPreSale, 'collection-other-branch'))
            ->assertForbidden();
    }

    public function test_collection_capture_is_not_available_when_delivery_agent_collects_on_delivery(): void
    {
        [$business, $branch, $seller, $preSale] = $this->preSale();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        $this->openCashRegister($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.pre-sales.collection.store', $preSale), $this->payload($preSale, 'collection-delivery-agent'))
            ->assertSessionHasErrors('collection');

        $this->assertDatabaseCount('route_pre_sale_collections', 0);
    }

    public function test_pre_sale_inertia_contract_exposes_collection_state_and_delivery_agent_message(): void
    {
        [$business, $branch, $seller, $preSale] = $this->preSale();
        $admin = $this->user($business, $branch, 'owner');
        Permissions::assignDirectPermissions($admin, [Permissions::ROUTES_COLLECTIONS_OVERRIDE]);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)->get(route('routes.pre-sales.show', $preSale))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('preSale.agreed_payment_method', 'cash')
                ->where('collectionResponsibility', 'pre_seller')
                ->where('collectionStatus', 'pending_collection')
                ->where('canRegisterCollection', true)
                ->where('canOverrideCollection', true)
                ->where('activeCollection', null)
                ->where('custodyStatus', null)
                ->where('deliveryAgentCollectionMessage', null));

        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);

        $this->actingAs($admin)->get(route('routes.pre-sales.show', $preSale))
            ->assertInertia(fn (Assert $page) => $page
                ->where('collectionResponsibility', 'delivery_agent')
                ->where('collectionStatus', 'pending_delivery_collection')
                ->where('canRegisterCollection', false)
                ->where('deliveryAgentCollectionMessage', 'El cobro se registrará durante la entrega.'));
    }

    private function preSale(?Business $business = null, ?Branch $branch = null, ?User $seller = null): array
    {
        if (! $business) {
            $business = Business::query()->create(['name' => 'Collection HTTP '.uniqid(), 'slug' => 'collection-http-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
            TenantSetting::query()->create(['business_id' => $business->id]);
            foreach (['routes', 'cash_register', 'branches'] as $module) {
                TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
            }
            $branch = BranchInventory::defaultBranchForBusiness($business);
            $seller = $this->user($business, $branch, 'pre_seller');
        }

        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => PreSale::STATUS_SUBMITTED,
            'subtotal' => 125,
            'discount_total' => 0,
            'total' => 125,
            'payment_method' => 'cash',
            'agreed_payment_method' => 'cash',
        ]);

        return [$business, $branch, $seller, $preSale];
    }

    private function user(Business $business, Branch $branch, string $role): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        Permissions::assignRole($user, $role);

        return $user;
    }

    private function openCashRegister(Business $business, Branch $branch, User $user): void
    {
        CashRegisterSession::query()->firstOrCreate(
            ['business_id' => $business->id, 'branch_id' => $branch->id, 'status' => 'open'],
            ['opened_by' => $user->id, 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()],
        );
    }

    private function payload(PreSale $preSale, string $key): array
    {
        return ['idempotency_key' => $key, 'amount' => $preSale->total, 'payment_method' => 'transfer', 'reference' => 'TRX-1001'];
    }
}
