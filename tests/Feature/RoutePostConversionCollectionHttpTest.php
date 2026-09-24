<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoutePostConversionCollectionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_pre_seller_can_view_only_its_own_pending_post_conversion_collections(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        [, , $otherSeller, $otherEntry] = $this->entry('pre_seller', $business, $branch);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'cash',
        ]);

        $this->as($seller, $business)->get(route('routes.mobile.post-conversion-collections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/Mobile/PostConversionCollections/Index')
                ->has('collections', 1)
                ->where('collections.0.entry_id', $entry->id)
                ->missing('collections.1')
                ->where('payment_policy.available', true)
                ->where('payment_policy.allowed_methods', ['cash', 'transfer']));

        $this->assertNotSame($entry->id, $otherEntry->id);
    }

    public function test_seller_collect_endpoint_derives_collector_and_rejects_injected_amount_and_collector(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->entry('pre_seller');
        $other = $this->user($business, $branch, 'pre_seller');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['transfer'], 'primary_payment_method' => 'transfer',
        ]);

        $this->as($seller, $business)->post(route('routes.post-conversion-collections.store'), [
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'payment_method' => 'transfer',
            'idempotency_key' => 'http-post-collection-0001',
            'amount' => '0.01',
            'collected_by' => $other->id,
        ])->assertSessionHasErrors(['amount', 'collected_by']);

        $this->as($seller, $business)->post(route('routes.post-conversion-collections.store'), [
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'payment_method' => 'transfer',
            'idempotency_key' => 'http-post-collection-0002',
        ])->assertRedirect()->assertSessionHas('success', 'Cobro registrado correctamente.');

        $this->assertDatabaseHas('route_post_conversion_collections', [
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'sale_id' => $sale->id,
            'collected_by' => $seller->id,
            'recorded_by' => $seller->id,
            'amount' => '123.47',
            'payment_method' => 'transfer',
        ]);
    }

    public function test_admin_override_persists_reason_and_seller_cannot_reverse(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        $admin = $this->user($business, $branch, 'admin');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash',
        ]);

        $this->as($admin, $business)->post(route('routes.post-conversion-collections.store'), [
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'payment_method' => 'cash',
            'collected_by' => $seller->id,
            'override_reason' => 'El preventista entregó la constancia al administrador.',
            'idempotency_key' => 'http-post-override-0001',
        ])->assertRedirect();

        $collection = \App\Models\RoutePostConversionCollection::query()->sole();
        $this->assertSame($seller->id, (int) $collection->collected_by);
        $this->assertSame($admin->id, (int) $collection->recorded_by);
        $this->assertSame('El preventista entregó la constancia al administrador.', $collection->override_reason);

        $this->as($seller, $business)->post(route('routes.post-conversion-collections.reversals.store', $collection), [
            'idempotency_key' => 'http-post-reverse-denied-0001',
            'reason_code' => 'wrong_amount',
            'explanation' => 'No autorizado.',
            'confirmed' => true,
        ])->assertForbidden();
    }

    public function test_admin_pre_sale_detail_exposes_post_conversion_context_separately_from_pre_sale_collections(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        $admin = $this->user($business, $branch, 'admin');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['transfer'], 'primary_payment_method' => 'transfer',
        ]);

        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('postConversionCollection.entry_id', $entry->id)
                ->where('postConversionCollection.agreed_method', 'cash')
                ->where('postConversionCollection.payment_policy.allowed_methods', ['transfer'])
                ->where('postConversionCollection.active_collection', null)
                ->where('postConversionCollection.can_collect', true)
                ->where('canRegisterCollection', false));
    }

    public function test_converted_pre_sale_collection_copy_uses_the_historical_pre_seller_snapshot(): void
    {
        [$business, $branch, , $entry] = $this->entry('pre_seller');
        $admin = $this->user($business, $branch, 'admin');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);

        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preSale.collection_responsibility', 'pre_seller')
                ->where('preSale.collection_message', 'Pendiente de cobro por el prevendedor.'));

        $this->assertStringContainsString('preSale.collection_message', file_get_contents(resource_path('js/Pages/Routes/PreSales/Show.tsx')));
    }

    public function test_converted_pre_sale_collection_copy_uses_the_historical_delivery_agent_snapshot(): void
    {
        [$business, $branch, , $entry] = $this->entry('delivery_agent');
        $admin = $this->user($business, $branch, 'admin');

        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preSale.collection_responsibility', 'delivery_agent')
                ->where('preSale.collection_message', 'El cobro se registrará durante la entrega.'));
    }

    public function test_immediate_paid_converted_pre_sale_detail_exposes_a_sale_without_post_conversion_collection_context(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->entry('pre_seller', null, null, 'immediate_paid');
        $admin = $this->user($business, $branch, 'admin');

        $sale->update([
            'payment_status' => 'paid',
            'amount_paid' => '123.47',
            'payment_method' => 'cash',
        ]);

        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('preSale.converted_sale.id', $sale->id)
                ->where('preSale.converted_sale.payment_status', 'paid')
                ->where('preSale.converted_sale.amount_paid', 123.47)
                ->where('preSale.converted_sale.payment_method', 'cash')
                ->where('preSale.collection_message', null)
                ->where('preSale.fel.status', 'not_requested')
                ->where('postConversionCollection', null));
    }

    private function as(User $user, Business $business)
    {
        return $this->withSession(['active_business_id' => $business->id])->actingAs($user);
    }

    /** @return array{Business, \App\Models\Branch, User, RouteDeliveryBatchPreSale, Sale} */
    private function entry(string $responsibility, ?Business $business = null, ?\App\Models\Branch $branch = null, string $workflow = 'per_order_collection'): array
    {
        if (! $business) {
            $business = Business::query()->create(['name' => 'Post HTTP '.uniqid(), 'slug' => 'post-http-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
            TenantSetting::query()->create(['business_id' => $business->id, 'route_cash_custody_policy' => 'collector_custody_until_settlement']);
            TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
            $branch = BranchInventory::defaultBranchForBusiness($business);
        }
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $seller->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $seller->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => PreSale::STATUS_CONVERTED, 'subtotal' => 123.47, 'discount_total' => 0, 'total' => 123.47, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash', 'converted_at' => now(), 'converted_by' => $seller->id]);
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'total' => 123.47, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'credit_balance' => 0, 'is_credit_sale' => false, 'document_type' => 'receipt', 'created_by' => $seller->id]);
        $preSale->update(['converted_sale_id' => $sale->id]);
        $batch = RouteDeliveryBatch::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'delivered_by' => $seller->id, 'status' => 'completed', 'stock_deduction_timing' => 'invoice', 'invoicing_mode' => 'manual', 'fel_automation_enabled' => false, 'delivery_tracking_snapshot' => 'external', 'collection_responsibility_snapshot' => $responsibility, 'collection_workflow_mode_snapshot' => $workflow, 'allowed_payment_methods_snapshot' => $workflow === 'immediate_paid' ? ['cash'] : ['cash', 'transfer'], 'primary_payment_method_snapshot' => 'cash', 'operation_settings_snapshotted_at' => now(), 'delivered_at' => now(), 'total_pre_sales' => 1, 'total_items' => 0, 'total_amount' => 123.47]);
        $entry = RouteDeliveryBatchPreSale::query()->create(['route_delivery_batch_id' => $batch->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'status' => 'delivered', 'payment_method' => 'cash', 'agreed_payment_method_snapshot' => 'cash', 'fel_dispatch_status' => 'not_requested']);

        return [$business, $branch, $seller, $entry, $sale];
    }

    private function user(Business $business, \App\Models\Branch $branch, string $role): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        Permissions::assignRole($user, $role);

        return $user->fresh();
    }
}
