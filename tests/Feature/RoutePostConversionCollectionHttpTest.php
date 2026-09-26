<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteDeliveryCollection;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteDeliveryRun;
use App\Models\RouteDeliveryStop;
use App\Models\RoutePostConversionCollection;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Services\Routes\RoutePostConversionCollectionService;
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

    public function test_pending_collection_modal_prefers_allowed_agreed_method_but_posts_selected_actual_method(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->entry('pre_seller', null, null, 'per_order_collection', null, 'transfer');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'cash',
        ]);

        $this->as($seller, $business)->get(route('routes.mobile.post-conversion-collections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('collections.0.agreed_method', 'transfer')
                ->where('payment_policy.allowed_methods', ['cash', 'transfer']));

        // There is no React test runner in this project; pin the modal's state-to-request contract.
        $source = file_get_contents(resource_path('js/Pages/Routes/Mobile/PostConversionCollections/Index.tsx'));
        $this->assertStringContainsString('payment_policy.allowed_methods.includes(row.agreed_method)', $source);
        $this->assertStringContainsString('? row.agreed_method', $source);
        $this->assertStringContainsString('value={form.data.payment_method}', $source);
        $this->assertStringContainsString("onChange={event => form.setData('payment_method', event.target.value as Method)}", $source);
        $this->assertStringNotContainsString("form.setData('payment_method', method);", $source);

        $this->as($seller, $business)->post(route('routes.post-conversion-collections.store'), [
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'modal-actual-cash-0001',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('route_post_conversion_collections', ['sale_id' => $sale->id, 'payment_method' => 'cash']);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'method' => 'cash']);
    }

    public function test_pending_collection_modal_uses_allowed_fallback_when_agreed_method_is_no_longer_allowed(): void
    {
        [$business, $branch, $seller] = $this->entry('pre_seller');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]);

        $this->as($seller, $business)->get(route('routes.mobile.post-conversion-collections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('collections.0.agreed_method', 'cash')
                ->where('payment_policy.allowed_methods', ['transfer']));

        $source = file_get_contents(resource_path('js/Pages/Routes/Mobile/PostConversionCollections/Index.tsx'));
        $this->assertStringContainsString("payment_policy.allowed_methods[0] ?? ''", $source);
    }

    public function test_general_pending_collections_inbox_keeps_a_closed_work_day_sale_visible_when_another_work_day_is_open(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'cash',
        ]);

        $closedWorkDay = $entry->batch->workDay;
        $this->assertSame('closed', $closedWorkDay->status);
        $nextZone = RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => 'Zona nueva '.uniqid(),
            'is_active' => true,
        ]);
        RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $nextZone->id,
            'seller_id' => $seller->id,
            'work_date' => today(),
            'status' => 'open',
            'started_at' => now(),
        ]);

        $this->as($seller, $business)->get(route('routes.mobile.zones'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/Mobile/Zones')
                ->where('canPostConversionCollect', true));

        $this->as($seller, $business)->get(route('routes.mobile.post-conversion-collections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('collections', 1)
                ->where('collections.0.entry_id', $entry->id));
    }

    public function test_general_pending_collections_access_is_hidden_without_the_collection_permission(): void
    {
        [$business, $branch] = $this->entry('pre_seller');
        $user = $this->user($business, $branch, 'cashier');
        Permissions::assignDirectPermissions($user, [Permissions::ROUTES_WORK]);

        $this->as($user->fresh(), $business)->get(route('routes.mobile.zones'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/Mobile/Zones')
                ->where('canPostConversionCollect', false));
    }

    public function test_general_pending_collections_inbox_excludes_other_sellers_branches_paid_sales_active_collections_and_delivery_agent_entries(): void
    {
        [$business, $branch, $seller, $eligible] = $this->entry('pre_seller');
        [, , , $otherSellerEntry] = $this->entry('pre_seller', $business, $branch);
        [, , , $paidEntry, $paidSale] = $this->entry('pre_seller', $business, $branch, 'per_order_collection', $seller);
        [, , , $capturedEntry] = $this->entry('pre_seller', $business, $branch, 'per_order_collection', $seller);
        [, , , $deliveryAgentEntry] = $this->entry('delivery_agent', $business, $branch, 'per_order_collection', $seller);
        $otherBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Sucursal alterna '.uniqid(), 'code' => 'ALT'.random_int(100, 999), 'is_active' => true]);
        $otherBranchSeller = $this->user($business, $otherBranch, 'pre_seller');

        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'cash',
        ]);
        $paidSale->update(['payment_status' => 'paid', 'amount_paid' => '123.47', 'payment_method' => 'cash']);
        app(RoutePostConversionCollectionService::class)->collect($capturedEntry, ['payment_method' => 'transfer'], $seller, 'pending-inbox-captured-0001');

        $this->as($seller, $business)->get(route('routes.mobile.post-conversion-collections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('collections', 1)
                ->where('collections.0.entry_id', $eligible->id)
                ->missing('collections.1'));

        $this->as($otherBranchSeller, $business)->get(route('routes.mobile.post-conversion-collections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('collections', 0));

        $this->assertNotSame($eligible->id, $otherSellerEntry->id);
        $this->assertNotSame($eligible->id, $paidEntry->id);
        $this->assertNotSame($eligible->id, $deliveryAgentEntry->id);
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

    public function test_pending_pre_seller_copy_tracks_the_current_sale_payment_state(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        $admin = $this->user($business, $branch, 'admin');
        Permissions::assignDirectPermissions($admin, [Permissions::ROUTES_POST_CONVERSION_COLLECTIONS_REVERSE]);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]);

        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertInertia(fn (Assert $page) => $page->where('preSale.collection_message', 'Pendiente de cobro por el prevendedor.'));

        $this->as($seller, $business)->post(route('routes.post-conversion-collections.store'), [
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'payment_method' => 'transfer',
            'idempotency_key' => 'copy-collect-first-0001',
        ])->assertSessionHasNoErrors();
        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertInertia(fn (Assert $page) => $page->where('preSale.collection_message', null));

        $collection = \App\Models\RoutePostConversionCollection::query()->sole();
        $this->as($admin, $business)->post(route('routes.post-conversion-collections.reversals.store', $collection), [
            'idempotency_key' => 'copy-reverse-first-0001',
            'reason_code' => 'wrong_amount',
            'explanation' => 'Se registró un cobro incorrecto.',
            'confirmed' => true,
        ])->assertSessionHasNoErrors();
        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertInertia(fn (Assert $page) => $page->where('preSale.collection_message', 'Pendiente de cobro por el prevendedor.'));

        $this->as($seller, $business)->post(route('routes.post-conversion-collections.store'), [
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'payment_method' => 'transfer',
            'idempotency_key' => 'copy-recollect-0001',
        ])->assertSessionHasNoErrors();
        $this->as($admin, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertInertia(fn (Assert $page) => $page->where('preSale.collection_message', null));
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

    public function test_cash_settlement_http_preserves_post_conversion_source_when_a_delivery_collection_has_the_same_id(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $result = app(RoutePostConversionCollectionService::class)->collect(
            $entry,
            ['payment_method' => 'cash'],
            $seller,
            'post-settlement-http-collision-0001',
        );
        $postCollection = RoutePostConversionCollection::query()->findOrFail($result->resultId);
        $deliveryCollection = $this->deliveryCollectionForEntry($entry, $seller, $postCollection->id);
        $owner = $this->user($business, $branch, 'owner');

        $this->assertSame($postCollection->id, $deliveryCollection->id, 'The regression fixture requires colliding numeric IDs.');

        $index = $this->as($owner, $business)->get(route('routes.cash-settlements.index', ['collector_id' => $seller->id]));
        $index->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Routes/CashSettlements/Index')
            ->where('eligible_collections', fn ($collections) => collect($collections)->contains(fn ($collection) =>
                $collection['origin'] === 'post_conversion_collection'
                && $collection['collection_id'] === $postCollection->id
                && $collection['sale_id'] === $postCollection->sale_id
                && $collection['pre_sale_id'] === $postCollection->pre_sale_id
                && $collection['payment_method'] === 'cash'
                && $collection['custody_status'] === 'held_by_collector'
            )));

        $this->as($owner, $business)->from(route('routes.cash-settlements.index'))->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-duplicate-source-0001',
            'collector_user_id' => $seller->id,
            'items' => [
                ['origin' => 'post_conversion_collection', 'collection_id' => $postCollection->id],
                ['origin' => 'post_conversion_collection', 'collection_id' => $postCollection->id],
            ],
        ])->assertRedirect(route('routes.cash-settlements.index'))->assertSessionHasErrors('sources');
        $this->assertDatabaseCount('route_cash_settlements', 0);

        $response = $this->as($owner, $business)->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-draft-0001',
            'collector_user_id' => $seller->id,
            'items' => [['origin' => 'post_conversion_collection', 'collection_id' => $postCollection->id]],
        ]);

        $settlement = RouteCashSettlement::query()->sole();
        $response->assertRedirect(route('routes.cash-settlements.show', $settlement));
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $settlement->id,
            'route_pre_sale_collection_id' => null,
            'route_delivery_collection_id' => null,
            'route_post_conversion_collection_id' => $postCollection->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('route_cash_settlement_items', [
            'route_cash_settlement_id' => $settlement->id,
            'route_delivery_collection_id' => $deliveryCollection->id,
        ]);

        $this->as($owner, $business)->get(route('routes.cash-settlements.show', $settlement))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/CashSettlements/Show')
                ->where('settlement.items.0.origin', 'post_conversion_collection')
                ->where('settlement.items.0.collection_id', $postCollection->id)
                ->where('settlement.items.0.sale_id', $postCollection->sale_id)
                ->where('settlement.items.0.pre_sale_id', $postCollection->pre_sale_id)
                ->where('settlement.items.0.route_delivery_batch_id', $entry->route_delivery_batch_id)
                ->where('settlement.items.0.collector.id', $seller->id)
                ->where('settlement.items.0.payment_method', 'cash')
                ->where('settlement.items.0.custody_status', 'held_by_collector'));

        $this->as($owner, $business)->post(route('routes.cash-settlements.cancel', $settlement), [
            'idempotency_key' => 'post-settlement-http-collision-cancel-0001',
            'cancellation_reason' => 'Probar selección compuesta.',
        ])->assertRedirect(route('routes.cash-settlements.index'));

        $this->as($owner, $business)->from(route('routes.cash-settlements.index'))->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-collision-pair-0001',
            'collector_user_id' => $seller->id,
            'items' => [
                ['origin' => 'post_conversion_collection', 'collection_id' => $postCollection->id],
                ['origin' => 'delivery_collection', 'collection_id' => $deliveryCollection->id],
            ],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $combined = RouteCashSettlement::query()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $combined->id,
            'route_post_conversion_collection_id' => $postCollection->id,
            'route_delivery_collection_id' => null,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $combined->id,
            'route_post_conversion_collection_id' => null,
            'route_delivery_collection_id' => $deliveryCollection->id,
            'is_active' => true,
        ]);
    }

    public function test_post_conversion_cash_settlement_completes_once_through_http(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $result = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-settlement-http-confirm-source-0001');
        $collection = RoutePostConversionCollection::query()->findOrFail($result->resultId);
        $owner = $this->user($business, $branch, 'owner');

        $this->as($owner, $business)->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-confirm-draft-0001',
            'collector_user_id' => $seller->id,
            'items' => [['origin' => 'post_conversion_collection', 'collection_id' => $collection->id]],
        ])->assertRedirect();
        $settlement = RouteCashSettlement::query()->sole();
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $owner->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->as($owner, $business)->post(route('routes.cash-settlements.confirm', $settlement), [
            'idempotency_key' => 'post-settlement-http-confirm-0001',
            'received_by' => $owner->id,
            'received_amount' => '123.47',
        ])->assertRedirect(route('routes.cash-settlements.show', $settlement));

        $this->assertSame('confirmed', $settlement->fresh()->status);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
        $this->assertDatabaseCount('cash_movements', 1);

        $this->as($owner, $business)->from(route('routes.cash-settlements.show', $settlement))->post(route('routes.cash-settlements.confirm', $settlement), [
            'idempotency_key' => 'post-settlement-http-confirm-again-0001',
            'received_by' => $owner->id,
            'received_amount' => '123.47',
        ])->assertRedirect(route('routes.cash-settlements.show', $settlement))->assertSessionHasErrors('settlement');
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_post_conversion_source_can_be_added_to_an_existing_draft_through_http(): void
    {
        [$business, $branch, $seller, $entry] = $this->entry('pre_seller');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $result = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-settlement-http-add-source-0001');
        $owner = $this->user($business, $branch, 'owner');
        $settlement = RouteCashSettlement::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'collector_user_id' => $seller->id,
            'recorded_by' => $owner->id,
            'expected_amount' => 0,
            'status' => 'draft',
        ]);

        $this->as($owner, $business)->post(route('routes.cash-settlements.items.store', $settlement), [
            'idempotency_key' => 'post-settlement-http-add-draft-0001',
            'items' => [['origin' => 'post_conversion_collection', 'collection_id' => $result->resultId]],
        ])->assertRedirect();

        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $settlement->id,
            'route_post_conversion_collection_id' => $result->resultId,
            'is_active' => true,
        ]);
        $this->assertSame('123.47', $settlement->fresh()->expected_amount);
    }

    public function test_cash_settlement_http_rejects_unknown_and_out_of_scope_post_conversion_sources(): void
    {
        [$business, $branch, $seller] = $this->entry('pre_seller');
        $owner = $this->user($business, $branch, 'owner');

        $this->as($owner, $business)->from(route('routes.cash-settlements.index'))->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-unknown-0001',
            'collector_user_id' => $seller->id,
            'items' => [['origin' => 'future_collection', 'collection_id' => 1]],
        ])->assertRedirect(route('routes.cash-settlements.index'))->assertSessionHasErrors('items.0.origin');

        [$foreignBusiness, , , $foreignEntry] = $this->entry('pre_seller');
        app(RouteBranchCollectionSettingsService::class)->save($foreignBusiness->id, $foreignEntry->batch->branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $foreignSeller = $foreignEntry->preSale->seller;
        $foreignResult = app(RoutePostConversionCollectionService::class)->collect($foreignEntry, ['payment_method' => 'cash'], $foreignSeller, 'post-settlement-http-foreign-source-0001');

        $this->as($owner, $business)->from(route('routes.cash-settlements.index'))->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-foreign-draft-0001',
            'collector_user_id' => $seller->id,
            'items' => [['origin' => 'post_conversion_collection', 'collection_id' => $foreignResult->resultId]],
        ])->assertRedirect(route('routes.cash-settlements.index'))->assertSessionHasErrors('sources');
        $this->assertDatabaseCount('route_cash_settlements', 0);
    }

    public function test_post_conversion_cash_settlement_rejects_collector_and_branch_mismatches(): void
    {
        [$business, $branch, $seller] = $this->entry('pre_seller');
        $owner = $this->user($business, $branch, 'owner');
        $otherSeller = $this->user($business, $branch, 'pre_seller');
        [, , , $otherCollectorEntry] = $this->entry('pre_seller', $business, $branch, 'per_order_collection', $otherSeller);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $otherCollectorResult = app(RoutePostConversionCollectionService::class)->collect(
            $otherCollectorEntry,
            ['payment_method' => 'cash'],
            $otherSeller,
            'post-settlement-http-other-collector-0001',
        );

        $this->as($owner, $business)->from(route('routes.cash-settlements.index'))->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-other-collector-draft-0001',
            'collector_user_id' => $seller->id,
            'items' => [['origin' => 'post_conversion_collection', 'collection_id' => $otherCollectorResult->resultId]],
        ])->assertRedirect(route('routes.cash-settlements.index'))->assertSessionHasErrors('sources');

        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal '.uniqid(),
            'code' => 'SETTLE-'.uniqid(),
            'is_active' => true,
        ]);
        $branchSeller = $this->user($business, $otherBranch, 'pre_seller');
        [, , , $otherBranchEntry] = $this->entry('pre_seller', $business, $otherBranch, 'per_order_collection', $branchSeller);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $otherBranch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $this->actingAs($branchSeller);
        $otherBranchResult = app(RoutePostConversionCollectionService::class)->collect(
            $otherBranchEntry,
            ['payment_method' => 'cash'],
            $branchSeller,
            'post-settlement-http-other-branch-0001',
        );

        $this->as($owner, $business)->from(route('routes.cash-settlements.index'))->post(route('routes.cash-settlements.store'), [
            'idempotency_key' => 'post-settlement-http-other-branch-draft-0001',
            'collector_user_id' => $seller->id,
            'items' => [['origin' => 'post_conversion_collection', 'collection_id' => $otherBranchResult->resultId]],
        ])->assertRedirect(route('routes.cash-settlements.index'))->assertSessionHasErrors('sources');

        $this->assertDatabaseCount('route_cash_settlements', 0);
    }

    private function as(User $user, Business $business)
    {
        return $this->withSession(['active_business_id' => $business->id])->actingAs($user);
    }

    /** @return array{Business, \App\Models\Branch, User, RouteDeliveryBatchPreSale, Sale} */
    private function entry(string $responsibility, ?Business $business = null, ?\App\Models\Branch $branch = null, string $workflow = 'per_order_collection', ?User $seller = null, string $agreedMethod = 'cash'): array
    {
        if (! $business) {
            $business = Business::query()->create(['name' => 'Post HTTP '.uniqid(), 'slug' => 'post-http-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
            TenantSetting::query()->create(['business_id' => $business->id, 'route_cash_custody_policy' => 'collector_custody_until_settlement']);
            TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
            $branch = BranchInventory::defaultBranchForBusiness($business);
        }
        $seller ??= $this->user($business, $branch, 'pre_seller');
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $seller->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $seller->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => PreSale::STATUS_CONVERTED, 'subtotal' => 123.47, 'discount_total' => 0, 'total' => 123.47, 'payment_method' => $agreedMethod, 'agreed_payment_method' => $agreedMethod, 'converted_at' => now(), 'converted_by' => $seller->id]);
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'total' => 123.47, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'credit_balance' => 0, 'is_credit_sale' => false, 'document_type' => 'receipt', 'created_by' => $seller->id]);
        $preSale->update(['converted_sale_id' => $sale->id]);
        $batch = RouteDeliveryBatch::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'delivered_by' => $seller->id, 'status' => 'completed', 'stock_deduction_timing' => 'invoice', 'invoicing_mode' => 'manual', 'fel_automation_enabled' => false, 'delivery_tracking_snapshot' => 'external', 'collection_responsibility_snapshot' => $responsibility, 'collection_workflow_mode_snapshot' => $workflow, 'allowed_payment_methods_snapshot' => $workflow === 'immediate_paid' ? ['cash'] : ['cash', 'transfer'], 'primary_payment_method_snapshot' => 'cash', 'operation_settings_snapshotted_at' => now(), 'delivered_at' => now(), 'total_pre_sales' => 1, 'total_items' => 0, 'total_amount' => 123.47]);
        $entry = RouteDeliveryBatchPreSale::query()->create(['route_delivery_batch_id' => $batch->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'status' => 'delivered', 'payment_method' => $agreedMethod, 'agreed_payment_method_snapshot' => $agreedMethod, 'fel_dispatch_status' => 'not_requested']);

        return [$business, $branch, $seller, $entry, $sale];
    }

    private function user(Business $business, \App\Models\Branch $branch, string $role): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        Permissions::assignRole($user, $role);

        return $user->fresh();
    }

    private function deliveryCollectionForEntry(RouteDeliveryBatchPreSale $entry, User $collector, int $collectionId): RouteDeliveryCollection
    {
        $batch = $entry->batch;
        $run = RouteDeliveryRun::query()->create([
            'business_id' => $batch->business_id,
            'branch_id' => $batch->branch_id,
            'delivery_user_id' => $collector->id,
            'created_by' => $collector->id,
            'status' => 'draft',
            'delivery_tracking_snapshot' => 'in_app',
            'collection_responsibility_snapshot' => 'delivery_agent',
        ]);
        $stop = RouteDeliveryStop::query()->create([
            'business_id' => $batch->business_id,
            'branch_id' => $batch->branch_id,
            'route_delivery_run_id' => $run->id,
            'route_delivery_batch_id' => $batch->id,
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'pre_sale_id' => $entry->pre_sale_id,
            'sale_id' => $entry->sale_id,
            'customer_id' => $entry->preSale->customer_id,
            'position' => 1,
            'delivery_tracking_snapshot' => 'in_app',
            'collection_responsibility_snapshot' => 'delivery_agent',
            'status' => 'pending',
            'assigned_by' => $collector->id,
            'assigned_at' => now(),
        ]);

        $collection = new RouteDeliveryCollection;
        $collection->id = $collectionId;
        $collection->fill([
            'business_id' => $batch->business_id,
            'branch_id' => $batch->branch_id,
            'sale_id' => $entry->sale_id,
            'pre_sale_id' => $entry->pre_sale_id,
            'route_delivery_stop_id' => $stop->id,
            'delivery_origin' => 'in_app_stop',
            'collected_by' => $collector->id,
            'recorded_by' => $collector->id,
            'amount' => '123.47',
            'payment_method' => 'cash',
            'collected_at' => now(),
            'cash_custody_policy_snapshot' => 'collector_custody_until_settlement',
            'custody_status' => 'held_by_collector',
            'cash_posting_state' => 'awaiting_physical_receipt',
            'status' => 'captured',
        ]);
        $collection->save();

        return $collection;
    }
}
