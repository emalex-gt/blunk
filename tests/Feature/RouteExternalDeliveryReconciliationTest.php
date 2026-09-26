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

    private function routeEntry(string $responsibility, bool $policyAware = false, string $timing = 'invoice', string $workflow = 'per_order_collection'): array
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
