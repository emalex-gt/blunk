<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteBranchCollectionSetting;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteExternalDeliveryReconciliation;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\ExternalDeliveryEligibility;
use App\Services\Routes\RouteDeliveryBatchService;
use App\Services\Routes\RouteDeliveryCollectionService;
use App\Services\Routes\RouteExternalDeliveryReconciliationService;
use App\Services\Routes\RouteExternalDeliveryReconciliationCorrectionService;
use App\Services\Routes\RoutePreSaleCollectionService;
use App\Services\Routes\RoutePreSalePreparationService;
use App\Services\Routes\RouteUnpaidSaleCancellationService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\SystemIntegrityAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Illuminate\Validation\ValidationException;

class RouteExternalDeliveryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_delivered_unpaid_external_operation_is_returned_without_erasing_delivery_or_payment_history(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-external-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
        $stock = ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertEquals(7, $stock->stock);
        $this->assertDatabaseHas('route_pending_collection_cases', ['sale_id' => $entry->sale_id, 'status' => 'open']);

        $payload = ['idempotency_key' => 'phase2a-external-return', 'reason' => 'Producto devuelto por el cliente', 'goods_received' => true];
        $first = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);
        $second = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->resultId, $second->resultId);
        $this->assertDatabaseHas('route_operation_returns', ['id' => $first->resultId, 'sale_id' => $entry->sale_id, 'route_external_delivery_reconciliation_item_id' => $source->id, 'route_delivery_stop_id' => null, 'status' => 'completed']);
        $this->assertDatabaseHas('route_external_delivery_reconciliation_items', ['id' => $source->id, 'delivery_status' => 'delivered']);
        $this->assertDatabaseHas('sales', ['id' => $entry->sale_id, 'status' => 'cancelled', 'payment_status' => 'unpaid']);
        $this->assertDatabaseHas('pre_sales', ['id' => $entry->pre_sale_id, 'status' => 'converted', 'converted_sale_id' => $entry->sale_id]);
        $this->assertDatabaseHas('route_pending_collection_cases', ['sale_id' => $entry->sale_id, 'status' => 'not_applicable']);
        $this->assertEquals(10, $stock->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['type' => 'sale_cancel', 'route_operation_return_id' => $first->resultId, 'quantity' => 3]);
        $this->assertSame(1, StockMovement::query()->where('route_operation_return_id', $first->resultId)->count());
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        try {
            app(RouteDeliveryCollectionService::class)->capturePostDeliveryFull($source->fresh(), [
                'amount' => 60, 'payment_method' => 'transfer', 'collected_by' => $actor->id, 'collected_at' => now()->toDateTimeString(),
            ], $actor);
            $this->fail('A returned, cancelled sale cannot be collected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
    }

    public function test_external_return_http_exposes_return_history_and_pre_sale_operational_state(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-http-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
        $this->as($actor, $business)->get(route('routes.delivery-batches.show', $entry->batch))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Routes/DeliveryBatches/Show')
                ->where('batch.pre_sales.0.can_register_return', true));
        $this->as($actor, $business)->post(route('routes.external-delivery-reconciliation-items.return', $source), [
            'idempotency_key' => 'phase2a-http-return', 'reason' => 'Devolución aceptada', 'goods_received' => true,
        ])->assertSessionHasNoErrors();

        $this->as($actor, $business)->get(route('routes.delivery-batches.show', $entry->batch))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Routes/DeliveryBatches/Show')
                ->where('batch.pre_sales.0.operation_return.status', 'completed')
                ->where('batch.pre_sales.0.reconciliation.delivery_status', 'delivered'));
        $this->as($actor, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('preSale.operational_status', 'operation_returned')
                ->where('preSale.converted_sale.id', $entry->sale_id));
    }

    public function test_route_return_rejects_paid_sale_and_unsafe_fel_without_stock_change(): void
    {
        foreach (['paid', 'certified', 'pending', 'unknown'] as $state) {
            [$business, $entry] = $this->routeEntry('delivery_agent', true);
            $actor = User::query()->findOrFail($entry->batch->delivered_by);
            $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                'idempotency_key' => 'phase2a-block-delivery-'.$state, 'delivery_status' => 'delivered', 'collected' => false,
            ], $actor);
            if ($state === 'paid') {
                $entry->sale->update(['payment_status' => 'paid', 'amount_paid' => $entry->sale->total]);
                SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $entry->sale_id, 'method' => 'card', 'amount' => $entry->sale->total]);
            } else {
                $entry->sale->update(['certification_status' => $state]);
            }
            try {
                app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal(RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId), [
                    'idempotency_key' => 'phase2a-block-return-'.$state, 'reason' => 'Devolución', 'goods_received' => true,
                ], $actor);
                $this->fail('Expected paid/FEL state to block the return.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($state === 'paid' ? 'refund' : 'fel', $exception->errors());
            }
            $this->assertSame('completed', $entry->sale->fresh()->status);
            $this->assertDatabaseCount('route_operation_returns', 0);
            $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        }
    }

    public function test_route_return_different_key_cannot_restore_stock_twice(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-once-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
        app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, ['idempotency_key' => 'phase2a-once-first', 'reason' => 'Devolución', 'goods_received' => true], $actor);
        try {
            app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, ['idempotency_key' => 'phase2a-once-second', 'reason' => 'Devolución', 'goods_received' => true], $actor);
            $this->fail('Expected second return to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
        $this->assertDatabaseCount('route_operation_returns', 1);
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
    }

    public function test_route_return_stock_failure_rolls_back_sale_case_and_event(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-rollback-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
        $payload = ['idempotency_key' => 'phase2a-rollback-return', 'reason' => 'Devolución', 'goods_received' => true];
        StockMovement::creating(function ($movement): void {
            if ($movement->route_operation_return_id) throw new \RuntimeException('Injected route return stock failure');
        });
        try {
            app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);
            $this->fail('Expected stock failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected route return stock failure', $exception->getMessage());
        } finally {
            StockMovement::flushEventListeners();
        }
        $this->assertDatabaseCount('route_operation_returns', 0);
        $this->assertDatabaseHas('route_pending_collection_cases', ['sale_id' => $entry->sale_id, 'status' => 'open']);
        $this->assertSame('completed', $entry->sale->fresh()->status);
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);
        $this->assertDatabaseCount('route_operation_returns', 1);
    }

    public function test_multiline_route_return_links_every_restoration_to_one_causal_event(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true, 'invoice', 'per_order_collection', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-multiline-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $result = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal(
            RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId),
            ['idempotency_key' => 'phase2a-multiline-return', 'reason' => 'Todo devuelto', 'goods_received' => true], $actor,
        );

        $movements = StockMovement::query()->where('route_operation_return_id', $result->resultId)->get();
        $this->assertCount(2, $movements);
        $this->assertSame(2, $movements->pluck('product_id')->unique()->count());
        $this->assertEqualsCanonicalizing([2, 3], $movements->pluck('quantity')->map(fn ($value) => (int) $value)->all());
        $this->assertFalse(collect(app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'stock'])['results']['stock'])
            ->contains(fn (array $issue) => $issue['issue_type'] === 'route_operation_return_stock_mismatch'));
    }

    public function test_picking_timing_route_return_restores_once_with_causal_link(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true, 'picking');
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-picking-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $result = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal(
            RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId),
            ['idempotency_key' => 'phase2a-picking-return', 'reason' => 'Todo devuelto', 'goods_received' => true], $actor,
        );
        $this->assertSame(1, StockMovement::query()->where('route_operation_return_id', $result->resultId)->count());
        $this->assertEquals(10, ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail()->stock);
        $this->assertFalse(collect(app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'stock'])['results']['stock'])
            ->contains(fn (array $issue) => $issue['issue_type'] === 'route_operation_return_stock_mismatch'));
    }

    public function test_pending_case_failure_rolls_back_return_sale_and_stock(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-case-fail-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
        $payload = ['idempotency_key' => 'phase2a-case-fail-return', 'reason' => 'Todo devuelto', 'goods_received' => true];
        \App\Models\RoutePendingCollectionCase::updating(function ($case): void {
            if ($case->status === 'not_applicable') throw new \RuntimeException('Injected pending-case failure');
        });
        try {
            app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);
            $this->fail('Expected pending-case failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected pending-case failure', $exception->getMessage());
        } finally {
            \App\Models\RoutePendingCollectionCase::flushEventListeners();
        }
        $this->assertDatabaseCount('route_operation_returns', 0);
        $this->assertDatabaseHas('route_pending_collection_cases', ['sale_id' => $entry->sale_id, 'status' => 'open']);
        $this->assertSame('completed', $entry->sale->fresh()->status);
        $this->assertEquals(7, ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail()->stock);
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);
        $this->assertDatabaseCount('route_operation_returns', 1);
    }

    public function test_legacy_paid_receipt_without_pre_sale_collection_link_requires_administrative_review(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $entry->batch->update([
            'delivery_tracking_snapshot' => null,
            'collection_responsibility_snapshot' => null,
        ]);
        $entry->sale->update(['payment_status' => 'paid', 'amount_paid' => $entry->sale->total, 'payment_method' => 'cash']);
        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $entry->sale_id,
            'method' => 'cash',
            'amount' => $entry->sale->total,
        ]);

        $eligibility = app(ExternalDeliveryEligibility::class)->forEntry($entry->fresh(['batch', 'sale']));

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('review_required', $eligibility['reason']);
    }

    public function test_delivery_agent_full_collection_creates_one_payment_without_accounts_receivable(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $reconciliation = RouteExternalDeliveryReconciliation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $entry->batch->branch_id,
            'route_delivery_batch_id' => $entry->route_delivery_batch_id,
            'opened_by' => $actor->id,
            'opened_at' => now(),
        ]);
        $item = RouteExternalDeliveryReconciliationItem::query()->create([
            'business_id' => $business->id,
            'branch_id' => $entry->batch->branch_id,
            'route_external_delivery_reconciliation_id' => $reconciliation->id,
            'route_delivery_batch_pre_sale_id' => $entry->id,
            'pre_sale_id' => $entry->pre_sale_id,
            'sale_id' => $entry->sale_id,
            'delivery_tracking_snapshot' => 'external',
            'collection_responsibility_snapshot' => 'delivery_agent',
            'delivery_status' => 'delivered',
            'reconciled_by' => $actor->id,
            'reconciled_at' => now(),
        ]);

        $collection = app(RouteDeliveryCollectionService::class)->captureFull($item, [
            'amount' => 60,
            'payment_method' => 'card',
            'collected_by' => $actor->id,
            'collected_at' => now()->toDateTimeString(),
        ], $actor);

        $this->assertSame('not_applicable', $collection->custody_status);
        $this->assertDatabaseCount('route_delivery_collections', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseHas('sales', ['id' => $entry->sale_id, 'payment_status' => 'paid', 'amount_paid' => 60, 'payment_method' => 'card']);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_delivery_agent_not_collected_reconciliation_is_idempotent_and_creates_no_payment(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $payload = ['idempotency_key' => 'external-reconcile-not-collected-key', 'delivery_status' => 'delivered', 'collected' => false];

        $first = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, $payload, $actor);
        $second = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, $payload, $actor);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertDatabaseCount('route_external_delivery_reconciliation_items', 1);
        $this->assertDatabaseCount('route_delivery_collections', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertSame(1, $second->responsePayload['progress']['reconciled']);
        $this->assertSame(0, $second->responsePayload['progress']['pending']);
    }

    public function test_delivery_result_correction_creates_an_append_only_revision_without_touching_payment(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $result = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'external-correction-source-key',
            'delivery_status' => 'delivered',
            'collected' => false,
        ], $actor);

        $corrected = app(RouteExternalDeliveryReconciliationCorrectionService::class)->correctDeliveryResult(
            RouteExternalDeliveryReconciliationItem::query()->findOrFail($result->resultId),
            ['delivery_status' => 'delivered', 'notes' => 'Recibió encargado', 'correction_reason' => 'Ajuste de nota'],
            $actor,
        );

        $this->assertSame('delivered', $corrected->delivery_status);
        $this->assertDatabaseHas('route_external_delivery_reconciliation_item_revisions', [
            'route_external_delivery_reconciliation_item_id' => $corrected->id,
            'version' => 1,
            'corrected_by' => $actor->id,
        ]);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_external_correction_preserves_optional_delivered_note_through_shared_rules(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $result = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'external-delivered-note-source-key',
            'delivery_status' => 'delivered',
            'notes' => 'Nota inicial',
            'collected' => false,
        ], $actor);

        $corrected = app(RouteExternalDeliveryReconciliationCorrectionService::class)->correctDeliveryResult(
            RouteExternalDeliveryReconciliationItem::query()->findOrFail($result->resultId),
            ['delivery_status' => 'delivered', 'notes' => 'Recibió recepción.', 'correction_reason' => 'Confirmación del cliente'],
            $actor,
        );

        $this->assertSame('delivered', $corrected->delivery_status);
        $this->assertNull($corrected->not_delivered_reason);
        $this->assertSame('Recibió recepción.', $corrected->notes);
    }

    public function test_admin_correction_cannot_turn_delivered_into_terminal_non_delivery_without_cancellation(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $result = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'external-correction-terminal-source-key', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);

        try {
            app(RouteExternalDeliveryReconciliationCorrectionService::class)->correctDeliveryResult(
                RouteExternalDeliveryReconciliationItem::query()->findOrFail($result->resultId),
                ['delivery_status' => 'not_delivered', 'not_delivered_reason' => 'customer_absent', 'correction_reason' => 'Entrega fallida'],
                $actor,
            );
            $this->fail('Expected terminal change to require cancellation orchestration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction', $exception->errors());
        }
        $this->assertDatabaseHas('route_external_delivery_reconciliation_items', ['id' => $result->resultId, 'delivery_status' => 'delivered']);
        $this->assertDatabaseCount('route_external_delivery_reconciliation_item_revisions', 0);
        $this->assertSame('completed', $entry->sale->fresh()->status);
    }

    public function test_immediate_cash_without_physical_receipt_stays_held_without_a_cash_session(): void
    {
        [$business, $item, $actor] = $this->deliveryAgentReconciliationItem();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);

        $collection = app(RouteDeliveryCollectionService::class)->captureFull($item, ['amount' => 60, 'payment_method' => 'cash', 'collected_by' => $actor->id, 'collected_at' => now()->toDateTimeString(), 'receive_cash_in_current_session' => false], $actor);

        $this->assertSame('held_by_collector', $collection->custody_status);
        $this->assertSame('awaiting_physical_receipt', $collection->cash_posting_state);
        $this->assertNull($collection->cash_register_session_id);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_immediate_cash_with_current_physical_receipt_creates_one_cash_movement(): void
    {
        [$business, $item, $actor] = $this->deliveryAgentReconciliationItem();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);

        $collection = app(RouteDeliveryCollectionService::class)->captureFull($item, ['amount' => 60, 'payment_method' => 'cash', 'collected_by' => $actor->id, 'collected_at' => now()->toDateTimeString(), 'receive_cash_in_current_session' => true], $actor);

        $this->assertSame('posted_to_branch_cash', $collection->custody_status);
        $this->assertSame('posted_to_current_session', $collection->cash_posting_state);
        $this->assertNotNull($collection->cash_register_session_id);
        $this->assertDatabaseHas('cash_movements', ['reference_type' => 'route_delivery_collection', 'reference_id' => $collection->id, 'amount' => 60]);
    }

    public function test_integrity_auditor_accepts_held_route_delivery_cash_without_a_movement(): void
    {
        [$business, $item, $actor] = $this->deliveryAgentReconciliationItem();
        app(RouteDeliveryCollectionService::class)->captureFull($item, ['amount' => 60, 'payment_method' => 'cash', 'collected_by' => $actor->id, 'collected_at' => now()->toDateTimeString()], $actor);

        $audit = app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'sales']);

        $this->assertFalse(collect($audit['results']['sales'])->contains(fn (array $issue) => $issue['code'] === 'cash_sale_without_cash_movement'));
    }

    public function test_not_delivered_rejects_collected_payment_without_persisting_reconciliation(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);

        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, ['idempotency_key' => 'external-not-delivered-collected-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'customer_absent', 'collected' => true, 'amount' => 60, 'payment_method' => 'transfer', 'collected_by' => $actor->id, 'collected_at' => now()->toDateTimeString()], $actor);
            $this->fail('Expected the contradictory payment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collected', $exception->errors());
        }

        $this->assertDatabaseCount('route_external_delivery_reconciliation_items', 0);
        $this->assertDatabaseCount('route_delivery_collections', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('completed', $entry->sale->fresh()->status);
    }

    public function test_unpaid_external_non_delivery_cancels_sale_and_restores_invoice_stock_once(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $stock = ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertEquals(7, $stock->stock);
        $payload = ['idempotency_key' => 'external-terminal-invoice-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'customer_absent', 'collected' => false];

        $first = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, $payload, $actor);
        $second = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, $payload, $actor);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->resultId, $second->resultId);
        $this->assertDatabaseHas('route_external_delivery_reconciliation_items', ['id' => $first->resultId, 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'customer_absent']);
        $this->assertDatabaseHas('sales', ['id' => $entry->sale_id, 'status' => 'cancelled', 'payment_status' => 'unpaid']);
        $this->assertStringContainsString('customer_absent', (string) $entry->sale->fresh()->cancellation_reason);
        $this->assertDatabaseHas('pre_sales', ['id' => $entry->pre_sale_id, 'status' => 'converted', 'converted_sale_id' => $entry->sale_id]);
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', ['id' => $entry->id, 'sale_id' => $entry->sale_id, 'status' => 'delivered']);
        $this->assertEquals(10, $stock->fresh()->stock);
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        $this->assertNull(StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->firstOrFail()->route_operation_return_id);
        try {
            app(RouteUnpaidSaleCancellationService::class)->cancel($entry, $actor, 'customer_absent');
            $this->fail('Expected an already cancelled Sale to reject a second stock return.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('route_pending_collection_cases', 0);
        $this->as($actor, $business)->get(route('routes.pre-sales.show', $entry->pre_sale_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('preSale.status', 'converted')
                ->where('preSale.operational_status', 'operation_cancelled')
                ->where('preSale.converted_sale.id', $entry->sale_id)
                ->where('preSale.converted_sale.status', 'cancelled'));
        $this->as($actor, $business)->get(route('routes.pre-sales.index', ['status' => 'converted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Index')
                ->where('preSales.data.0.id', $entry->pre_sale_id)
                ->where('preSales.data.0.operational_status', 'operation_cancelled'));

        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                ...$payload, 'idempotency_key' => 'external-terminal-invoice-different-key',
            ], $actor);
            $this->fail('Expected a second key to find the terminal reconciliation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
    }

    public function test_route_return_auditor_detects_missing_causal_stock_movement(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-audit-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
        $result = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, [
            'idempotency_key' => 'phase2a-audit-return', 'reason' => 'Devolución completa', 'goods_received' => true,
        ], $actor);
        $audit = app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'stock']);
        $this->assertFalse(collect($audit['results']['stock'])->contains(fn (array $issue) => $issue['issue_type'] === 'route_operation_return_stock_mismatch'));

        StockMovement::query()->where('route_operation_return_id', $result->resultId)->delete();
        $audit = app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'stock']);
        $this->assertTrue(collect($audit['results']['stock'])->contains(fn (array $issue) => $issue['issue_type'] === 'route_operation_return_stock_mismatch'));
    }

    public function test_route_return_auditor_detects_excess_causal_stock_movement(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2a-audit-extra-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $result = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal(RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId), [
            'idempotency_key' => 'phase2a-audit-extra-return', 'reason' => 'Devolución completa', 'goods_received' => true,
        ], $actor);
        $movement = StockMovement::query()->where('route_operation_return_id', $result->resultId)->firstOrFail();
        StockMovement::query()->create($movement->only(['business_id', 'branch_id', 'product_id', 'type', 'quantity', 'previous_stock', 'new_stock', 'note', 'created_by', 'route_operation_return_id']));

        $audit = app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'stock']);
        $this->assertTrue(collect($audit['results']['stock'])->contains(fn (array $issue) => $issue['issue_type'] === 'route_operation_return_stock_mismatch'));
    }

    public function test_unpaid_external_non_delivery_restores_picking_stock_without_reversing_picking_twice(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true, 'picking');
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $stock = ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('picking', $entry->batch->stock_deduction_timing);
        $this->assertEquals(7, $stock->stock);
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'pre_sale_picking')->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale')->count());

        app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'external-terminal-picking-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'damaged_goods', 'collected' => false,
        ], $actor);

        $this->assertEquals(10, $stock->fresh()->stock);
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'pre_sale_picking')->count());
        $this->assertDatabaseHas('sales', ['id' => $entry->sale_id, 'status' => 'cancelled']);
    }

    public function test_terminal_reconciliation_http_reports_operation_cancellation(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);

        $this->as($actor, $business)->post(route('routes.delivery-batches.external-reconciliation.store', [$entry->batch, $entry]), [
            'idempotency_key' => 'external-terminal-http-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'business_closed', 'collected' => false,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Operación anulada y productos devueltos al inventario.');
        $this->assertDatabaseHas('sales', ['id' => $entry->sale_id, 'status' => 'cancelled']);
    }

    public function test_not_delivered_rejects_payment_fields_even_if_collected_is_false(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                'idempotency_key' => 'external-terminal-manipulated-key', 'delivery_status' => 'not_delivered',
                'not_delivered_reason' => 'customer_absent', 'collected' => false, 'payment_method' => 'cash',
            ], $actor);
            $this->fail('Expected incompatible payment fields to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collected', $exception->errors());
        }
        $this->assertDatabaseCount('route_external_delivery_reconciliation_items', 0);
    }

    public function test_pending_and_unknown_fel_states_block_terminal_non_delivery(): void
    {
        foreach (['pending', 'unknown'] as $status) {
            [$business, $entry] = $this->routeEntry('delivery_agent', true);
            $actor = User::query()->findOrFail($entry->batch->delivered_by);
            $entry->sale->update(['certification_status' => $status]);
            try {
                app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                    'idempotency_key' => 'external-fel-'.$status.'-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'customer_absent', 'collected' => false,
                ], $actor);
                $this->fail('Expected the '.$status.' fiscal state to block cancellation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('fel', $exception->errors());
            }
            $this->assertSame('completed', $entry->sale->fresh()->status);
            $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
            $this->assertSame(0, RouteExternalDeliveryReconciliationItem::query()->where('business_id', $business->id)->count());
        }
    }

    public function test_immediate_paid_non_delivery_blocks_before_reconciliation_or_stock_return(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true, 'invoice', 'immediate_paid');
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $this->assertSame('paid', $entry->sale->payment_status);
        $this->assertSame(1, SalePayment::query()->where('sale_id', $entry->sale_id)->count());
        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                'idempotency_key' => 'external-paid-no-delivery-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'customer_absent', 'collected' => false,
            ], $actor);
            $this->fail('Expected paid operation to require causal reversal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }
        $this->assertSame('completed', $entry->sale->fresh()->status);
        $this->assertSame(0, RouteExternalDeliveryReconciliationItem::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
    }

    public function test_delivered_immediate_paid_cash_is_refunded_from_the_current_open_session_without_reversing_original_payment(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true, 'invoice', 'immediate_paid');
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2b1-delivered-immediate', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
        $originalSession = \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->sole();
        $originalSession->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]);
        $currentSession = \App\Models\CashRegisterSession::query()->create([
            'business_id' => $business->id, 'branch_id' => $entry->batch->branch_id, 'opened_by' => $actor->id,
            'status' => 'open', 'opening_amount' => 100, 'expected_cash' => 100, 'opened_at' => now()->addSecond(),
        ]);
        \App\Support\CashRegister::recordMovement($currentSession, 'opening', 100, null, null, 'Apertura', $actor->id);
        $returnContext = app(\App\Services\Routes\RouteOperationReturnService::class)->previewForEntry($entry->fresh());
        $this->assertTrue($returnContext['eligible']);
        $this->assertTrue($returnContext['requires_cash_refund']);
        $this->assertSame(60.0, $returnContext['refund_amount']);
        $this->assertSame('cash', $returnContext['original_payment_method']);
        $batchSource = file_get_contents(resource_path('js/Pages/Routes/DeliveryBatches/Show.tsx'));
        $this->assertStringContainsString('refund_cash_confirmed: entry.return_context?.requires_cash_refund ? true : null', $batchSource);
        $this->assertStringContainsString('Confirmar devolución y reembolso', $batchSource);
        $this->assertStringContainsString('se devolverán ahora en efectivo desde la caja abierta.', $batchSource);

        $result = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, [
            'idempotency_key' => 'phase2b1-cash-refund', 'reason' => 'Cliente devolvió el pedido',
            'goods_received' => true, 'refund_cash_confirmed' => true,
        ], $actor);
        $replay = app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, [
            'idempotency_key' => 'phase2b1-cash-refund', 'reason' => 'Cliente devolvió el pedido',
            'goods_received' => true, 'refund_cash_confirmed' => true,
        ], $actor);

        $payment = SalePayment::query()->where('sale_id', $entry->sale_id)->sole();
        $this->assertSame('captured', $payment->status);
        $this->assertTrue($replay->replayed);
        $this->assertSame($result->resultId, $replay->resultId);
        $this->assertSame($entry->id, (int) $payment->route_immediate_paid_entry_id);
        $this->assertDatabaseHas('sale_refunds', [
            'route_operation_return_id' => $result->resultId, 'sale_id' => $entry->sale_id,
            'sale_payment_id' => $payment->id, 'amount' => 60, 'status' => 'confirmed',
            'original_payment_method' => 'cash', 'refund_method' => 'cash',
            'cash_register_session_id' => $currentSession->id,
        ]);
        $refund = \App\Models\SaleRefund::query()->sole();
        $this->assertDatabaseHas('cash_movements', [
            'id' => $refund->cash_movement_id, 'cash_register_session_id' => $currentSession->id,
            'type' => 'sale_refund_cash', 'amount' => -60, 'reference_type' => 'sale_refund',
            'reference_id' => $refund->id,
        ]);
        $this->assertSame(1, \App\Models\CashMovement::query()->where('cash_register_session_id', $originalSession->id)->where('type', 'sale_cash')->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->where('cash_register_session_id', $originalSession->id)->where('type', 'sale_refund_cash')->count());
        $this->assertDatabaseHas('sales', ['id' => $entry->sale_id, 'status' => 'cancelled', 'payment_status' => 'paid', 'amount_paid' => 60]);
        $this->assertDatabaseHas('pre_sales', ['id' => $entry->pre_sale_id, 'status' => 'converted', 'converted_sale_id' => $entry->sale_id]);
        $this->assertDatabaseHas('route_external_delivery_reconciliation_items', ['id' => $source->id, 'delivery_status' => 'delivered']);
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail()->stock);
        $this->assertDatabaseCount('sale_refunds', 1);
        $this->assertSame(1, \App\Models\CashMovement::query()->where('type', 'sale_refund_cash')->count());
        $this->assertFalse(app(\App\Services\Routes\RouteOperationReturnService::class)->previewForEntry($entry->fresh())['eligible']);
        $cashSummary = \App\Support\CashRegister::summary($currentSession);
        $this->assertSame(60.0, $cashSummary['cash_refunds']);
        $this->assertSame(40.0, $cashSummary['expected_cash']);
        $salesIssues = collect(app(SystemIntegrityAuditor::class)->audit([
            'business' => $business->id,
            'section' => 'sales',
        ])['results']['sales'])->pluck('issue_type');
        $this->assertNotContains('cancelled_sale_cash_not_reversed', $salesIssues);

        TenantModule::query()->updateOrCreate(
            ['business_id' => $business->id, 'module' => 'reports'],
            ['is_enabled' => true, 'enabled_at' => now()],
        );
        $this->as($actor, $business)
            ->get(route('reports.daily', ['date' => now()->toDateString(), 'payment_method' => 'cash']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.0.value', 100)
                ->where('summary.1.value', 0)
                ->where('summary.2.label', 'Reembolsos')
                ->where('summary.2.value', 60)
                ->where('summary.5.value', 40));
        $this->as($actor, $business)
            ->get(route('reports.sales', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.1.value', 60)
                ->where('summary.6.label', 'Total reembolsado')
                ->where('summary.6.value', 60));
        try {
            app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, [
                'idempotency_key' => 'phase2b1-cash-refund-other-key', 'reason' => 'Cliente devolvió el pedido',
                'goods_received' => true, 'refund_cash_confirmed' => true,
            ], $actor);
            $this->fail('A confirmed refund cannot be repeated with another key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
    }

    public function test_cash_refund_blocks_legacy_noncash_unsafe_fel_and_missing_current_cash_session(): void
    {
        foreach (['legacy', 'noncash', 'certified', 'pending', 'unknown', 'no_session'] as $case) {
            [$business, $entry] = $this->routeEntry('delivery_agent', true, 'invoice', 'immediate_paid');
            $actor = User::query()->findOrFail($entry->batch->delivered_by);
            $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                'idempotency_key' => 'phase2b1-block-delivery-'.$case, 'delivery_status' => 'delivered', 'collected' => false,
            ], $actor);
            $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
            if ($case === 'legacy') {
                SalePayment::query()->where('sale_id', $entry->sale_id)->update(['route_immediate_paid_entry_id' => null]);
            } elseif ($case === 'noncash') {
                SalePayment::query()->where('sale_id', $entry->sale_id)->update(['method' => 'card']);
                $entry->sale->update(['payment_method' => 'card']);
            } elseif (in_array($case, ['certified', 'pending', 'unknown'], true)) {
                $entry->sale->update(['certification_status' => $case]);
            } elseif ($case === 'no_session') {
                \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->update(['status' => 'closed', 'closed_at' => now()]);
            }

            try {
                app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, [
                    'idempotency_key' => 'phase2b1-block-return-'.$case, 'reason' => 'Devolución',
                    'goods_received' => true, 'refund_cash_confirmed' => true,
                ], $actor);
                $this->fail('Expected Phase 2B1 eligibility to block '.$case.'.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(in_array($case, ['certified', 'pending', 'unknown'], true) ? 'fel' : ($case === 'no_session' ? 'cash_register' : 'refund'), $exception->errors());
            }
            $this->assertSame(0, \App\Models\SaleRefund::query()->where('business_id', $business->id)->count());
            $this->assertSame(0, \App\Models\RouteOperationReturn::query()->where('business_id', $business->id)->count());
            $this->assertSame('completed', $entry->sale->fresh()->status);
        }
    }

    public function test_cash_refund_failures_roll_back_financial_stock_and_sale_effects_then_allow_retry(): void
    {
        foreach (['cash_movement', 'stock', 'sale'] as $stage) {
            [$business, $entry] = $this->routeEntry('delivery_agent', true, 'invoice', 'immediate_paid');
            $actor = User::query()->findOrFail($entry->batch->delivered_by);
            $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                'idempotency_key' => 'phase2b1-rollback-delivery-'.$stage, 'delivery_status' => 'delivered', 'collected' => false,
            ], $actor);
            $source = RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId);
            $payload = [
                'idempotency_key' => 'phase2b1-rollback-return-'.$stage, 'reason' => 'Devolución',
                'goods_received' => true, 'refund_cash_confirmed' => true,
            ];
            if ($stage === 'cash_movement') {
                \App\Models\CashMovement::creating(function ($movement): void {
                    if ($movement->type === 'sale_refund_cash') throw new \RuntimeException('Injected refund movement failure');
                });
            } elseif ($stage === 'stock') {
                StockMovement::creating(function ($movement): void {
                    if ($movement->route_operation_return_id) throw new \RuntimeException('Injected refund stock failure');
                });
            } else {
                \App\Models\Sale::updating(function ($sale): void {
                    if ($sale->status === 'cancelled') throw new \RuntimeException('Injected refund sale failure');
                });
            }
            try {
                app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);
                $this->fail('Expected injected '.$stage.' failure.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Injected refund', $exception->getMessage());
            } finally {
                \App\Models\CashMovement::flushEventListeners();
                StockMovement::flushEventListeners();
                \App\Models\Sale::flushEventListeners();
            }
            $this->assertSame(0, \App\Models\SaleRefund::query()->where('business_id', $business->id)->count());
            $this->assertSame(0, \App\Models\RouteOperationReturn::query()->where('business_id', $business->id)->count());
            $this->assertSame('completed', $entry->sale->fresh()->status);
            $this->assertSame('paid', $entry->sale->fresh()->payment_status);
            $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->whereNotNull('route_operation_return_id')->count());
            $this->assertSame(0, \App\Models\CashMovement::query()->where('business_id', $business->id)->where('type', 'sale_refund_cash')->count());
            $this->assertSame(7.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail()->stock);

            app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal($source, $payload, $actor);
            $this->assertSame(1, \App\Models\SaleRefund::query()->where('business_id', $business->id)->count());
            $this->assertSame(1, \App\Models\CashMovement::query()->where('business_id', $business->id)->where('type', 'sale_refund_cash')->count());
            $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail()->stock);
        }
    }

    public function test_immediate_paid_cash_refund_restores_picking_stock_exactly_once(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true, 'picking', 'immediate_paid');
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $delivery = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'phase2b1-picking-delivery', 'delivery_status' => 'delivered', 'collected' => false,
        ], $actor);
        app(\App\Services\Routes\RouteOperationReturnService::class)->completeExternal(
            RouteExternalDeliveryReconciliationItem::query()->findOrFail($delivery->resultId),
            ['idempotency_key' => 'phase2b1-picking-refund', 'reason' => 'Devolución', 'goods_received' => true, 'refund_cash_confirmed' => true],
            $actor,
        );

        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail()->stock);
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        $this->assertDatabaseCount('sale_refunds', 1);
    }

    public function test_failed_sale_cancellation_rolls_back_reconciliation_and_stock_then_can_retry(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $stock = ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail();
        $payload = ['idempotency_key' => 'external-terminal-rollback-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'address_issue', 'collected' => false];
        \App\Models\Sale::updating(function ($sale): void {
            if ($sale->status === 'cancelled') throw new \RuntimeException('Intentional terminal cancellation failure.');
        });
        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, $payload, $actor);
            $this->fail('Expected cancellation failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Intentional terminal cancellation failure.', $exception->getMessage());
        } finally {
            \App\Models\Sale::flushEventListeners();
        }
        $this->assertSame('completed', $entry->sale->fresh()->status);
        $this->assertEquals(7, $stock->fresh()->stock);
        $this->assertDatabaseCount('route_external_delivery_reconciliation_items', 0);
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
        app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, $payload, $actor);
        $this->assertEquals(10, $stock->fresh()->stock);
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale_cancel')->count());
    }

    public function test_other_not_delivered_reason_requires_a_note(): void
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);

        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, ['idempotency_key' => 'external-other-without-note-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'other', 'collected' => false], $actor);
            $this->fail('Expected a note validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notes', $exception->errors());
        }
        $this->assertDatabaseCount('route_external_delivery_reconciliation_items', 0);
    }

    public function test_pre_seller_paid_non_delivery_requires_financial_reversal_before_reconciliation(): void
    {
        [$business, $entry] = $this->routeEntry('pre_seller');
        $actor = User::query()->findOrFail($entry->batch->delivered_by);

        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, ['idempotency_key' => 'external-pre-seller-not-delivered-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'customer_rejected', 'collected' => false], $actor);
            $this->fail('Expected paid provenance to require reversal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }

        $this->assertDatabaseCount('route_external_delivery_reconciliation_items', 0);
        $this->assertSame('paid', $entry->sale->fresh()->payment_status);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('route_delivery_collections', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_collection_override_preserves_actual_collector_and_authenticated_recorder(): void
    {
        [$business, $item, $actor] = $this->deliveryAgentReconciliationItem();
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $actor->current_branch_id, 'is_active' => true]);
        Permissions::assignRole($collector, 'pre_seller');

        $collection = app(RouteDeliveryCollectionService::class)->captureFull($item, ['amount' => 60, 'payment_method' => 'check', 'collected_by' => $collector->id, 'collected_at' => now()->toDateTimeString(), 'override_reason' => 'El entregador reportó el cobro al supervisor'], $actor);

        $this->assertSame($collector->id, $collection->collected_by);
        $this->assertSame($actor->id, $collection->recorded_by);
        $this->assertSame('El entregador reportó el cobro al supervisor', $collection->override_reason);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_delivery_collection_cannot_create_a_second_payment_or_collection(): void
    {
        [$business, $item, $actor] = $this->deliveryAgentReconciliationItem();
        $data = ['amount' => 60, 'payment_method' => 'card', 'collected_by' => $actor->id, 'collected_at' => now()->toDateTimeString()];
        app(RouteDeliveryCollectionService::class)->captureFull($item, $data, $actor);

        try {
            app(RouteDeliveryCollectionService::class)->captureFull($item, $data, $actor);
            $this->fail('Expected duplicate collection validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
        $this->assertDatabaseCount('route_delivery_collections', 1);
        $this->assertDatabaseCount('sale_payments', 1);
    }

    public function test_only_external_tracking_snapshot_is_eligible_for_external_reconciliation(): void
    {
        [, $entry] = $this->deliveryAgentEntry();
        $entry->batch->update(['delivery_tracking_snapshot' => 'in_app']);

        $eligibility = app(ExternalDeliveryEligibility::class)->forEntry($entry->fresh(['batch', 'sale']));

        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('review_required', $eligibility['reason']);
    }

    public function test_external_reconciliation_prefers_the_historical_agreed_method_and_posts_the_user_selected_method(): void
    {
        [$business, $sourceEntry] = $this->deliveryAgentEntry();
        RouteDeliveryBatchPreSale::query()->whereKey($sourceEntry->id)->delete();
        RouteDeliveryBatch::query()->whereKey($sourceEntry->route_delivery_batch_id)->delete();
        $batch = RouteDeliveryBatch::query()->create([
            'business_id' => $business->id,
            'branch_id' => $sourceEntry->batch->branch_id,
            'route_work_day_id' => $sourceEntry->batch->route_work_day_id,
            'route_zone_id' => $sourceEntry->batch->route_zone_id,
            'delivered_by' => $sourceEntry->batch->delivered_by,
            'status' => 'completed',
            'stock_deduction_timing' => 'invoice',
            'invoicing_mode' => 'manual',
            'fel_automation_enabled' => false,
            'delivery_tracking_snapshot' => 'external',
            'collection_responsibility_snapshot' => 'delivery_agent',
            'collection_workflow_mode_snapshot' => 'per_order_collection',
            'allowed_payment_methods_snapshot' => ['cash', 'transfer'],
            'primary_payment_method_snapshot' => 'cash',
            'operation_settings_snapshotted_at' => now(),
            'delivered_at' => now(),
            'total_pre_sales' => 1,
            'total_items' => 1,
            'total_amount' => 60,
        ]);
        $entry = RouteDeliveryBatchPreSale::query()->create([
            'route_delivery_batch_id' => $batch->id,
            'pre_sale_id' => $sourceEntry->pre_sale_id,
            'sale_id' => $sourceEntry->sale_id,
            'status' => 'delivered',
            'payment_method' => 'transfer',
            'agreed_payment_method_snapshot' => 'transfer',
            'fel_dispatch_status' => 'not_requested',
        ]);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);

        $this->as($actor, $business)->get(route('routes.delivery-batches.show', $entry->batch))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/DeliveryBatches/Show')
                ->where('batch.pre_sales.0.agreed_payment_method_snapshot', 'transfer')
                ->where('batch.pre_sales.0.payment_policy.allowed_methods', ['cash', 'transfer'])
                ->where('batch.pre_sales.0.payment_policy.primary_method', 'cash'));

        // There is no React test runner in this project; pin the select-to-request contract.
        $source = file_get_contents(resource_path('js/Pages/Routes/DeliveryBatches/Show.tsx'));
        $this->assertStringContainsString("['customer_absent', 'Cliente ausente']", $source);
        $this->assertStringContainsString("['customer_rejected', 'Cliente rechazó la entrega']", $source);
        $this->assertStringContainsString("['address_issue', 'Problema con la dirección']", $source);
        $this->assertStringContainsString("['business_closed', 'Negocio cerrado']", $source);
        $this->assertStringContainsString("['damaged_goods', 'Mercancía dañada']", $source);
        $this->assertStringContainsString("['other', 'Otro']", $source);
        $this->assertStringContainsString('allowed.includes(entry.agreed_payment_method_snapshot as Method)', $source);
        $this->assertStringContainsString('allowed.includes(entry.payment_policy.primary_method as Method)', $source);
        $this->assertStringContainsString("allowed[0] ?? 'cash'", $source);
        $this->assertStringContainsString('value={method}', $source);
        $this->assertStringContainsString('onChange={(event) => setMethod(event.target.value as Method)}', $source);
        $this->assertStringContainsString('payment_method: method', $source);
        $this->assertStringContainsString('method === \'cash\'', $source);
        $this->assertStringContainsString('Confirmo que este efectivo está siendo recibido físicamente ahora en la caja abierta actual.', $source);

        $this->as($actor, $business)->post(route('routes.delivery-batches.external-reconciliation.store', [$entry->batch, $entry]), [
            'idempotency_key' => 'external-ui-selected-cash-0001',
            'delivery_status' => 'delivered',
            'collected' => true,
            'amount' => 60,
            'payment_method' => 'cash',
            'collected_by' => $actor->id,
            'collected_at' => now()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('route_delivery_collections', ['sale_id' => $entry->sale_id, 'payment_method' => 'cash']);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $entry->sale_id, 'method' => 'cash']);
    }

    public function test_collection_rejects_an_actor_from_another_tenant(): void
    {
        [$business, $item] = $this->deliveryAgentReconciliationItem();
        $otherBusiness = Business::query()->create(['name' => 'Other '.uniqid(), 'slug' => 'other-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $otherActor = User::factory()->create(['business_id' => $otherBusiness->id, 'current_branch_id' => BranchInventory::defaultBranchForBusiness($otherBusiness)->id, 'is_active' => true]);

        try {
            app(RouteDeliveryCollectionService::class)->captureFull($item, ['amount' => 60, 'payment_method' => 'cash', 'collected_by' => $otherActor->id, 'collected_at' => now()->toDateTimeString()], $otherActor);
            $this->fail('Expected tenant isolation to reject the actor.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('route_delivery_collections', 0);
    }

    public function test_destructive_correction_is_blocked_without_revision_or_financial_change(): void
    {
        [$business, $item, $actor] = $this->deliveryAgentReconciliationItem();

        try {
            app(RouteExternalDeliveryReconciliationCorrectionService::class)->correctDeliveryResult($item, [
                'delivery_status' => 'delivered', 'correction_reason' => 'Intento no permitido', 'amount' => 1,
            ], $actor);
            $this->fail('Expected a destructive correction validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction', $exception->errors());
        }
        $this->assertDatabaseCount('route_external_delivery_reconciliation_item_revisions', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_certified_sale_cannot_be_terminally_reconciled_or_have_stock_restored(): void
    {
        [$business, $entry] = $this->routeEntry('delivery_agent', true);
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $stock = ProductBranchStock::query()->where('business_id', $business->id)->firstOrFail();
        $stockBefore = $stock->stock;
        $reservationsBefore = StockReservation::query()->where('business_id', $business->id)->count();
        $entry->sale->update(['certification_status' => 'certified']);

        try {
            app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
                'idempotency_key' => 'external-invariants-key', 'delivery_status' => 'not_delivered', 'not_delivered_reason' => 'business_closed', 'collected' => false,
            ], $actor);
            $this->fail('Expected fiscal guard.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fel', $exception->errors());
        }

        $this->assertSame($stockBefore, ProductBranchStock::query()->findOrFail($stock->id)->stock);
        $this->assertSame($reservationsBefore, StockReservation::query()->where('business_id', $business->id)->count());
        $this->assertSame('certified', $entry->sale->fresh()->certification_status);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('route_external_delivery_reconciliation_items', 0);
    }

    private function deliveryAgentEntry(): array
    {
        return $this->routeEntry('delivery_agent');
    }

    private function routeEntry(string $responsibility, bool $policyAware = false, string $timing = 'invoice', string $workflow = 'per_order_collection', bool $twoLines = false): array
    {
        $business = Business::query()->create(['name' => 'External '.uniqid(), 'slug' => 'external-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($user, 'owner');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'allow_receipts' => true, 'allow_invoices' => true, 'route_collection_responsibility' => $responsibility, 'route_delivery_tracking' => 'external', 'route_pre_sale_stock_deduction_timing' => $timing]);
        if ($policyAware) {
            RouteBranchCollectionSetting::query()->create(['branch_id' => $branch->id, 'collection_workflow_mode' => $workflow, 'allowed_payment_methods' => ['cash', 'card', 'transfer', 'check'], 'primary_payment_method' => 'cash']);
        }
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'cash_register', 'is_enabled' => true, 'enabled_at' => now()]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $user->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $user->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF', 'country' => 'GT']);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'EXT-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => $timing === 'picking' ? PreSale::STATUS_SUBMITTED : PreSale::STATUS_PICKED, 'subtotal' => 60, 'discount_total' => 0, 'total' => 60, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash', 'picked_at' => $timing === 'picking' ? null : now(), 'picked_by' => $timing === 'picking' ? null : $user->id]);
        $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'picked_quantity' => 3, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 60]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $user->id]);
        if ($twoLines) {
            $otherProduct = Product::query()->create(['business_id' => $business->id, 'name' => 'Otro producto '.uniqid(), 'code' => 'EXT-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
            ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $otherProduct->id, 'stock' => 10]);
            $otherItem = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $otherProduct->id, 'quantity' => 2, 'picked_quantity' => 2, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 40]);
            StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $otherProduct->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $otherItem->id, 'quantity' => 2, 'status' => 'active', 'created_by' => $user->id]);
            $preSale->update(['subtotal' => 100, 'total' => 100]);
        }
        \App\Models\CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        if ($timing === 'picking') {
            \Illuminate\Support\Facades\DB::transaction(fn () => app(RoutePreSalePreparationService::class)->prepare($preSale, [['id' => $item->id, 'picked_quantity' => 3]], $user, 'picking'));
        }
        if ($responsibility === 'pre_seller') {
            app(RoutePreSaleCollectionService::class)->capture($preSale, ['amount' => 60, 'payment_method' => 'cash', 'idempotency_key' => 'external-pre-seller-collection-key'], $user);
        }

        $delivery = app(RouteDeliveryBatchService::class)->deliverAll($workDay, $user, 'external-delivery-fixture-key');

        return [$business, RouteDeliveryBatchPreSale::query()->with(['batch', 'sale'])->where('route_delivery_batch_id', $delivery->resultId)->firstOrFail()];
    }

    private function deliveryAgentReconciliationItem(): array
    {
        [$business, $entry] = $this->deliveryAgentEntry();
        $actor = User::query()->findOrFail($entry->batch->delivered_by);
        $reconciliation = RouteExternalDeliveryReconciliation::query()->create(['business_id' => $business->id, 'branch_id' => $entry->batch->branch_id, 'route_delivery_batch_id' => $entry->route_delivery_batch_id, 'opened_by' => $actor->id, 'opened_at' => now()]);
        $item = RouteExternalDeliveryReconciliationItem::query()->create(['business_id' => $business->id, 'branch_id' => $entry->batch->branch_id, 'route_external_delivery_reconciliation_id' => $reconciliation->id, 'route_delivery_batch_pre_sale_id' => $entry->id, 'pre_sale_id' => $entry->pre_sale_id, 'sale_id' => $entry->sale_id, 'delivery_tracking_snapshot' => 'external', 'collection_responsibility_snapshot' => 'delivery_agent', 'delivery_status' => 'delivered', 'reconciled_by' => $actor->id, 'reconciled_at' => now()]);

        return [$business, $item, $actor];
    }

    private function as(User $user, Business $business)
    {
        return $this->withSession(['active_business_id' => $business->id])->actingAs($user);
    }
}
