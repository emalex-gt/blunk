<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\FelReconciliationRequest;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteWorkDay;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RoutePreSaleCollection;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockReservation;
use App\Models\StockMovement;
use App\Models\TenantModule;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Routes\RouteDeliveryBatchService;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Services\Routes\RoutePreSaleCollectionService;
use App\Services\Routes\RoutePreSaleReceiptService;
use App\Jobs\RoutePreSaleAutomaticFelJob;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class RouteDeliveryBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permissions::syncDefaults();
    }

    public function test_delivery_schema_persists_a_pre_sale_payment_method_and_delivery_batches(): void
    {
        $this->assertTrue(Schema::hasColumn('pre_sales', 'payment_method'));
        $this->assertTrue(Schema::hasTable('route_delivery_batches'));
        $this->assertTrue(Schema::hasTable('route_delivery_batch_pre_sales'));
    }

    public function test_delivery_schema_persists_policy_and_agreed_method_snapshots(): void
    {
        $this->assertTrue(Schema::hasColumn('route_delivery_batches', 'collection_workflow_mode_snapshot'));
        $this->assertTrue(Schema::hasColumn('route_delivery_batches', 'allowed_payment_methods_snapshot'));
        $this->assertTrue(Schema::hasColumn('route_delivery_batches', 'primary_payment_method_snapshot'));
        $this->assertTrue(Schema::hasColumn('route_delivery_batch_pre_sales', 'agreed_payment_method_snapshot'));
    }

    public function test_per_order_delivery_agent_converts_without_cash_and_freezes_policy_snapshots(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'transfer');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer', 'cash'],
            'primary_payment_method' => 'transfer',
        ]);

        $result = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-per-order-agent-key');

        $batch = \App\Models\RouteDeliveryBatch::query()->findOrFail($result->resultId);
        $entry = $batch->preSales()->firstOrFail();
        $sale = $preSale->fresh()->convertedSale;
        $this->assertSame('per_order_collection', $batch->collection_workflow_mode_snapshot);
        $this->assertSame(['cash', 'transfer'], $batch->allowed_payment_methods_snapshot);
        $this->assertSame('transfer', $batch->primary_payment_method_snapshot);
        $this->assertSame('transfer', $entry->agreed_payment_method_snapshot);
        $this->assertSame('delivery_agent', $batch->collection_responsibility_snapshot);
        $this->assertNotNull($batch->operation_settings_snapshotted_at);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame(0.0, (float) $sale->amount_paid);
        $this->assertNull($sale->payment_method);
        $this->assertSame(0, $sale->payments()->count());
        $this->assertDatabaseCount('route_pre_sale_collections', 0);
        $this->assertDatabaseCount('route_delivery_collections', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_per_order_pre_seller_materializes_a_prior_cash_collection_once_without_posting_cash_again(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'transfer');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'route_collection_responsibility' => 'pre_seller',
            'route_cash_custody_policy' => 'immediate_branch_register',
        ]);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'transfer',
        ]);
        $this->openEmptyCashRegister($business, $branch, $user);
        $captured = app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total,
            'payment_method' => 'cash',
            'idempotency_key' => 'prior-cash-collection-0001',
        ], $user);
        $collection = RoutePreSaleCollection::query()->findOrFail($captured->resultId);
        $this->assertSame(1, CashMovement::query()->count());

        $first = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'prior-cash-delivery-0001');
        $replay = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'prior-cash-delivery-0001');
        $sale = $preSale->fresh()->convertedSale;
        $payment = $sale->payments()->sole();

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->resultId, $replay->resultId);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('60.00', $sale->amount_paid);
        $this->assertSame('cash', $sale->payment_method);
        $this->assertSame('cash', $payment->method);
        $this->assertSame($collection->id, (int) $payment->route_pre_sale_collection_id);
        $this->assertSame('linked', $collection->fresh()->status);
        $this->assertSame(1, CashMovement::query()->count());
        $this->assertDatabaseCount('route_post_conversion_collections', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_a_previously_captured_collection_survives_a_live_method_and_responsibility_change(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'transfer');
        $settings = app(RouteBranchCollectionSettingsService::class);
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'transfer',
        ]);
        $this->openEmptyCashRegister($business, $branch, $user);
        app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total, 'payment_method' => 'cash', 'idempotency_key' => 'historic-capture-0001',
        ], $user);

        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'historic-capture-delivery-0001');

        $sale = $preSale->fresh()->convertedSale;
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('cash', $sale->payment_method);
        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_a_prior_collection_can_convert_after_workflow_switch_without_a_new_cash_session(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'transfer');
        $settings = app(RouteBranchCollectionSettingsService::class);
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'transfer',
        ]);
        $session = $this->openEmptyCashRegister($business, $branch, $user);
        app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total, 'payment_method' => 'transfer', 'idempotency_key' => 'workflow-switch-capture-0001',
        ], $user);
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);

        $this->assertSame([], app(RouteDeliveryBatchService::class)->policyPreflightPreview($preSale->workDay));
        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'workflow-switch-delivery-0001');

        $sale = $preSale->fresh()->convertedSale;
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('transfer', $sale->payments()->sole()->method);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_per_order_pre_seller_without_prior_collection_still_creates_an_unpaid_sale(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'transfer');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]);

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'without-prior-collection-0001');

        $sale = $preSale->fresh()->convertedSale;
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame('0.00', $sale->amount_paid);
        $this->assertSame(0, $sale->payments()->count());
        $this->assertDatabaseCount('route_pre_sale_collections', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_held_prior_cash_collection_marks_sale_paid_without_posting_branch_cash(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'transfer');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'transfer',
        ]);
        $this->openEmptyCashRegister($business, $branch, $user);
        $collectionId = app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total, 'payment_method' => 'cash', 'idempotency_key' => 'held-cash-prior-0001',
        ], $user)->resultId;
        $this->assertSame(0, CashMovement::query()->count());

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'held-cash-delivery-0001');

        $sale = $preSale->fresh()->convertedSale;
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('60.00', $sale->amount_paid);
        $this->assertSame($collectionId, (int) $sale->payments()->sole()->route_pre_sale_collection_id);
        $this->assertSame('held_by_collector', RoutePreSaleCollection::query()->findOrFail($collectionId)->custody_status);
        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_prior_collection_with_a_non_cash_method_creates_no_cash_movement(): void
    {
        foreach (['card', 'transfer'] as $method) {
            [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
            app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
                'collection_workflow_mode' => 'per_order_collection',
                'allowed_payment_methods' => ['cash', 'card', 'transfer'],
                'primary_payment_method' => 'cash',
            ]);
            $this->openEmptyCashRegister($business, $branch, $user);
            app(RoutePreSaleCollectionService::class)->capture($preSale, [
                'amount' => $preSale->total, 'payment_method' => $method, 'idempotency_key' => 'noncash-prior-'.$method,
            ], $user);

            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'noncash-delivery-'.$method);

            $sale = $preSale->fresh()->convertedSale;
            $this->assertSame('paid', $sale->payment_status);
            $this->assertSame($method, $sale->payments()->sole()->method);
            $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        }
    }

    public function test_a_prior_collection_with_a_changed_final_sale_total_rolls_back_conversion(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $this->openEmptyCashRegister($business, $branch, $user);
        $collectionId = app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total, 'payment_method' => 'cash', 'idempotency_key' => 'partial-picking-prior-0001',
        ], $user)->resultId;
        $preSale->items()->sole()->update(['picked_quantity' => 2]);
        StockReservation::query()->where('source_id', $preSale->id)->where('source_type', 'pre_sale')->update(['quantity' => 2]);

        try {
            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'partial-picking-delivery-0001');
            $this->fail('A paid pre-sale cannot become a smaller paid Sale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection', $exception->errors(), json_encode($exception->errors()));
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('route_delivery_batches', 0);
        $this->assertSame('captured', RoutePreSaleCollection::query()->findOrFail($collectionId)->status);
    }

    public function test_policy_preview_blocks_a_prior_collection_that_no_longer_matches_the_pre_sale_total(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $this->openEmptyCashRegister($business, $branch, $user);
        $collectionId = app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total, 'payment_method' => 'cash', 'idempotency_key' => 'preview-mismatch-capture-0001',
        ], $user)->resultId;
        RoutePreSaleCollection::query()->whereKey($collectionId)->update(['amount' => '59.00']);

        $blocks = app(RouteDeliveryBatchService::class)->policyPreflightPreview($preSale->workDay);
        $this->assertSame('collection_amount_mismatch', $blocks[0]['reason_code'] ?? null);
        $this->assertSame($preSale->id, $blocks[0]['pre_sale_id'] ?? null);
    }

    public function test_direct_receipt_conversion_cannot_post_a_second_payment_for_an_already_collected_pre_sale(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $this->openEmptyCashRegister($business, $branch, $user);
        app(RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total, 'payment_method' => 'cash', 'idempotency_key' => 'direct-double-prior-0001',
        ], $user);

        try {
            app(RoutePreSaleReceiptService::class)->convertToInternalReceipt($preSale, [
                'idempotency_key' => 'direct-double-receipt-0001',
                'payment_condition' => 'paid',
                'payment_method' => 'cash',
            ], $user);
            $this->fail('Direct conversion must not post a second financial effect.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection', $exception->errors());
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertSame(1, RoutePreSaleCollection::query()->count());
    }

    public function test_immediate_paid_converts_a_pre_seller_cash_pre_sale_without_a_legacy_collection(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        $this->openEmptyCashRegister($business, $branch, $user);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);

        $result = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immediate-pre-seller-cash-key');

        $batch = \App\Models\RouteDeliveryBatch::query()->findOrFail($result->resultId);
        $entry = $batch->preSales()->firstOrFail();
        $sale = $preSale->fresh()->convertedSale()->firstOrFail();
        $movement = CashMovement::query()->where('business_id', $business->id)->sole();

        $this->assertSame('immediate_paid', $batch->collection_workflow_mode_snapshot);
        $this->assertSame('cash', $entry->agreed_payment_method_snapshot);
        $this->assertSame('cash', $entry->payment_method);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(60.0, (float) $sale->amount_paid);
        $this->assertSame(0.0, (float) $sale->credit_balance);
        $this->assertFalse((bool) $sale->is_credit_sale);
        $this->assertNull($sale->due_date);
        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame('cash', $sale->payments()->sole()->method);
        $this->assertSame(60.0, (float) $sale->payments()->sole()->amount);
        $this->assertSame($entry->id, (int) $sale->payments()->sole()->route_immediate_paid_entry_id);
        $this->assertSame('sale_cash', $movement->type);
        $this->assertSame($sale->id, (int) $movement->reference_id);
        $this->assertSame(60.0, (float) $movement->amount);
        $this->assertDatabaseCount('route_pre_sale_collections', 0);
        $this->assertDatabaseCount('route_delivery_collections', 0);
        $this->assertDatabaseCount('route_pending_collection_cases', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
        $this->assertDatabaseCount('route_cash_settlement_items', 0);
    }

    public function test_immediate_paid_uses_each_agreed_method_as_the_real_payment_method_for_delivery_agents(): void
    {
        foreach (['cash', 'card', 'transfer', 'check'] as $method) {
            [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', $method);
            TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
            $session = $this->openEmptyCashRegister($business, $branch, $user);
            $this->setPreSaleTotal($preSale, 123.47);
            app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
                'collection_workflow_mode' => 'immediate_paid',
                'allowed_payment_methods' => ['cash', 'card', 'transfer', 'check'],
                'primary_payment_method' => 'cash',
            ]);

            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immediate-method-'.$method);

            $sale = $preSale->fresh()->convertedSale()->firstOrFail();
            $entry = RouteDeliveryBatchPreSale::query()->where('sale_id', $sale->id)->sole();
            $payment = $sale->payments()->sole();

            $this->assertSame('paid', $sale->payment_status);
            $this->assertSame($method, $sale->payment_method);
            $this->assertSame(123.47, (float) $sale->amount_paid);
            $this->assertSame(123.47, (float) $payment->amount);
            $this->assertSame($method, $payment->method);
            $this->assertSame($method, $entry->agreed_payment_method_snapshot);
            $this->assertSame($method, $entry->payment_method);
            $this->assertSame($method === 'cash' ? 1 : 0, CashMovement::query()->where('business_id', $business->id)->count());
            if ($method === 'cash') {
                $movement = CashMovement::query()->where('business_id', $business->id)->sole();
                $this->assertSame($session->id, (int) $movement->cash_register_session_id);
                $this->assertSame(123.47, (float) $movement->amount);
            }
            $this->assertDatabaseCount('route_pre_sale_collections', 0);
            $this->assertDatabaseCount('route_delivery_collections', 0);
            $this->assertDatabaseCount('customer_account_movements', 0);
        }
    }

    public function test_immediate_paid_requires_an_open_cash_session_without_creating_any_financial_fact(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('invoice', 'card');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['card'],
            'primary_payment_method' => 'card',
        ]);

        try {
            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immediate-no-cash-key');
            $this->fail('Expected immediate paid delivery to require an open cash session.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cash_session_required', $exception->errors());
        }

        $this->assertDatabaseCount('route_delivery_batches', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
    }

    public function test_immediate_paid_same_key_replay_preserves_the_original_policy_and_creates_no_duplicate_payment_or_cash_movement(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        $this->openEmptyCashRegister($business, $branch, $user);
        $settings = app(RouteBranchCollectionSettingsService::class);
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'cash',
        ]);

        $first = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immediate-replay-key');
        $batch = \App\Models\RouteDeliveryBatch::query()->findOrFail($first->resultId);
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]);

        $replay = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immediate-replay-key');

        $this->assertTrue($replay->replayed);
        $this->assertSame($batch->id, $replay->resultId);
        $this->assertSame('immediate_paid', $batch->fresh()->collection_workflow_mode_snapshot);
        $this->assertSame(['cash', 'transfer'], $batch->fresh()->allowed_payment_methods_snapshot);
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, SalePayment::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, CashMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_immediate_paid_preserves_existing_picking_stock_timing_without_a_second_deduction(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('picking', 'card');
        ProductBranchStock::query()->where('product_id', $product->id)->update(['stock' => 7]);
        PreSaleItem::query()->where('pre_sale_id', $preSale->id)->update(['stock_deducted_quantity' => 3]);
        StockReservation::query()->where('source_id', $preSale->id)->update(['status' => 'consumed', 'consumed_at' => now()]);
        $this->openEmptyCashRegister($business, $branch, $user);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['card'],
            'primary_payment_method' => 'card',
        ]);

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immediate-picking-key');

        $this->assertSame('paid', $preSale->fresh()->convertedSale->payment_status);
        $this->assertSame(7.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_immediate_paid_keeps_existing_fel_automation_dispatch_behavior(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openEmptyCashRegister($business, $branch, $user);
        $this->configureFelAvailability($business, $branch);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        config(['fel.route_automation_enabled' => true]);
        Queue::fake();
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['card'],
            'primary_payment_method' => 'card',
        ]);

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immediate-fel-key');

        $this->assertSame('paid', $preSale->fresh()->convertedSale->payment_status);
        Queue::assertPushed(RoutePreSaleAutomaticFelJob::class);
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', [
            'pre_sale_id' => $preSale->id,
            'fel_dispatch_status' => 'queued',
        ]);
    }

    public function test_immediate_paid_rolls_back_each_financial_write_failure_and_allows_a_retry(): void
    {
        foreach ([
            RouteDeliveryBatchPreSale::class,
            SalePayment::class,
            CashMovement::class,
        ] as $model) {
            [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('invoice', 'cash');
            $this->openEmptyCashRegister($business, $branch, $user);
            app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
                'collection_workflow_mode' => 'immediate_paid',
                'allowed_payment_methods' => ['cash'],
                'primary_payment_method' => 'cash',
            ]);

            $this->assertImmediatePaidCreatingFailureRollsBack($model, $preSale, $user, 'route-delivery-immediate-rollback-'.class_basename($model));

            $this->assertSame(0, \App\Models\RouteDeliveryBatch::query()->where('business_id', $business->id)->count());
            $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
            $this->assertSame(0, SalePayment::query()->where('business_id', $business->id)->count());
            $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
            $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));

            $retry = app(RouteDeliveryBatchService::class)->deliverAll($preSale->fresh()->workDay, $user, 'route-delivery-immediate-rollback-'.class_basename($model));
            $this->assertNotNull($retry->resultId);
            $this->assertDatabaseHas('sales', ['id' => $preSale->fresh()->converted_sale_id, 'payment_status' => 'paid']);
        }
    }

    public function test_policy_snapshot_constraints_accept_legacy_nulls_and_reject_invalid_bundles_and_methods(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        $legacyId = $this->insertPolicySnapshotBatch($business, $branch, $user, $preSale);
        $this->assertNotNull($legacyId);

        foreach ([
            ['collection_workflow_mode_snapshot' => 'per_order_collection'],
            ['collection_workflow_mode_snapshot' => 'unknown', 'allowed_payment_methods_snapshot' => json_encode(['cash']), 'primary_payment_method_snapshot' => 'cash'],
            ['collection_workflow_mode_snapshot' => 'per_order_collection', 'allowed_payment_methods_snapshot' => json_encode('cash'), 'primary_payment_method_snapshot' => 'cash'],
            ['collection_workflow_mode_snapshot' => 'per_order_collection', 'allowed_payment_methods_snapshot' => json_encode([]), 'primary_payment_method_snapshot' => 'cash'],
            ['collection_workflow_mode_snapshot' => 'per_order_collection', 'allowed_payment_methods_snapshot' => json_encode(['crypto']), 'primary_payment_method_snapshot' => 'crypto'],
            ['collection_workflow_mode_snapshot' => 'per_order_collection', 'allowed_payment_methods_snapshot' => json_encode(['cash']), 'primary_payment_method_snapshot' => 'transfer'],
        ] as $invalid) {
            try {
                $this->insertPolicySnapshotBatch($business, $branch, $user, $preSale, $invalid);
                $this->fail('Expected the policy snapshot constraint to reject an invalid bundle.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        try {
            DB::table('route_delivery_batch_pre_sales')->insert([
                'route_delivery_batch_id' => $legacyId,
                'pre_sale_id' => $preSale->id,
                'status' => 'delivered',
                'payment_method' => 'cash',
                'agreed_payment_method_snapshot' => 'crypto',
                'fel_dispatch_status' => 'not_requested',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected the agreed payment method snapshot constraint to reject an unknown method.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_policy_snapshots_are_immutable_while_non_snapshot_updates_remain_allowed(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'transfer');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'transfer',
        ]);
        $result = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-immutable-policy-key');
        $batch = \App\Models\RouteDeliveryBatch::query()->findOrFail($result->resultId);
        $entry = $batch->preSales()->firstOrFail();

        $batch->update(['notes' => 'Nota permitida']);
        $entry->update(['fel_dispatch_status' => 'certified']);
        $this->assertSame('Nota permitida', $batch->fresh()->notes);
        $this->assertSame('certified', $entry->fresh()->fel_dispatch_status);

        try {
            $batch->update(['primary_payment_method_snapshot' => 'cash']);
            $this->fail('Expected immutable batch snapshots to reject an update.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            $entry->update(['agreed_payment_method_snapshot' => 'cash']);
            $this->fail('Expected immutable entry snapshots to reject an update.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_policy_aware_child_fails_closed_for_a_corrupted_policy(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        DB::table('route_branch_collection_settings')->where('branch_id', $branch->id)->update(['allowed_payment_methods' => json_encode(['cash', 'cash'])]);

        try {
            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-corrupt-policy-key');
            $this->fail('Expected a corrupt persisted policy to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('route_collection_policy_invalid', $exception->errors());
        }
        $this->assertDatabaseCount('route_delivery_batches', 0);
    }

    public function test_policy_aware_child_blocks_missing_or_disallowed_agreed_methods_without_a_partial_batch(): void
    {
        foreach ([
            [null, 'missing_agreed_payment_method'],
            ['card', 'payment_method_not_allowed'],
        ] as [$agreedMethod, $reason]) {
            [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', $agreedMethod);
            TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
            app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
                'collection_workflow_mode' => 'per_order_collection',
                'allowed_payment_methods' => ['cash'],
                'primary_payment_method' => 'cash',
            ]);

            try {
                app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-policy-method-'.uniqid());
                $this->fail('Expected the policy-aware child to reject the agreed method.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($reason, $exception->errors());
            }
            $this->assertDatabaseCount('route_delivery_batches', 0);
            $this->assertDatabaseCount('sales', 0);
        }
    }

    public function test_policy_aware_child_blocks_an_entire_work_day_when_one_pre_sale_is_invalid(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);
        $invalid = $this->additionalPickedPreSale($preSale, $user, null);

        try {
            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-policy-atomic-key');
            $this->fail('Expected one invalid pre-sale to block the entire child work day.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('missing_agreed_payment_method', $exception->errors());
        }

        $this->assertDatabaseCount('route_delivery_batches', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(PreSale::STATUS_PICKED, $preSale->fresh()->status);
        $this->assertSame(PreSale::STATUS_PICKED, $invalid->fresh()->status);
    }

    public function test_policy_aware_delivery_replay_uses_the_original_snapshots_after_the_branch_policy_changes(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        $settings = app(RouteBranchCollectionSettingsService::class);
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'cash',
        ]);

        $first = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-policy-replay-key');
        $batch = \App\Models\RouteDeliveryBatch::query()->findOrFail($first->resultId);
        $entry = $batch->preSales()->firstOrFail();
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]);

        $replay = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-policy-replay-key');

        $this->assertTrue($replay->replayed);
        $this->assertSame($batch->id, $replay->resultId);
        $this->assertSame('per_order_collection', $batch->fresh()->collection_workflow_mode_snapshot);
        $this->assertSame(['cash', 'transfer'], $batch->fresh()->allowed_payment_methods_snapshot);
        $this->assertSame('cash', $batch->fresh()->primary_payment_method_snapshot);
        $this->assertSame('cash', $entry->fresh()->agreed_payment_method_snapshot);
    }

    public function test_deliver_all_snapshots_external_tracking_and_collection_responsibility(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'route_delivery_tracking' => 'external',
            'route_collection_responsibility' => 'delivery_agent',
        ]);
        $this->openCashRegister($business, $branch, $user);

        $result = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-snapshot-key');

        TenantSetting::query()->where('business_id', $business->id)->update([
            'route_delivery_tracking' => 'in_app',
            'route_collection_responsibility' => 'pre_seller',
        ]);

        $batch = \App\Models\RouteDeliveryBatch::query()->findOrFail($result->resultId);

        $this->assertSame('external', $batch->delivery_tracking_snapshot);
        $this->assertSame('delivery_agent', $batch->collection_responsibility_snapshot);
        $this->assertNull($batch->collection_workflow_mode_snapshot);
        $this->assertNull($batch->allowed_payment_methods_snapshot);
        $this->assertNull($batch->primary_payment_method_snapshot);
        $this->assertNull($batch->preSales()->firstOrFail()->agreed_payment_method_snapshot);
    }

    public function test_deliver_all_creates_paid_receipt_payment_and_cash_movement_for_picked_cash_pre_sale(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('invoice', 'cash');
        $this->openCashRegister($business, $branch, $user);

        $result = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-batch-cash-key');

        $this->assertDatabaseHas('route_delivery_batches', ['id' => $result->resultId, 'business_id' => $business->id, 'status' => 'completed']);
        $this->assertDatabaseHas('sales', ['id' => $preSale->refresh()->converted_sale_id, 'document_type' => 'receipt', 'payment_status' => 'paid', 'payment_method' => 'cash']);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $preSale->converted_sale_id, 'method' => 'cash', 'amount' => 60]);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(7.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->count());
    }

    public function test_deliver_all_rejects_missing_payment_method_without_creating_a_sale(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', null);
        $this->openCashRegister($business, $branch, $user);

        try {
            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-missing-payment-key');
            $this->fail('Expected a payment method validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('agreed_payment_method', $exception->errors());
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
    }

    public function test_deliver_all_requires_an_open_cash_register(): void
    {
        [, , $user, $preSale] = $this->pickedPreSale('invoice', 'card');

        $this->expectException(ValidationException::class);
        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-no-cash-key');
    }

    public function test_delivery_agent_creates_an_unpaid_receipt_without_a_payment_or_cash_movement(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'cash');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        $this->openCashRegister($business, $branch, $user);

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-agent-key');

        $sale = $preSale->refresh()->convertedSale;
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame(0.0, (float) $sale->amount_paid);
        $this->assertNull($sale->payment_method);
        $this->assertFalse((bool) $sale->is_credit_sale);
        $this->assertSame(0.0, (float) $sale->credit_balance);
        $this->assertSame(0, $sale->payments()->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_picking_timing_delivery_does_not_decrease_stock_or_consume_reservations_twice(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('picking', 'card');
        ProductBranchStock::query()->where('product_id', $product->id)->update(['stock' => 7]);
        PreSaleItem::query()->where('pre_sale_id', $preSale->id)->update(['stock_deducted_quantity' => 3]);
        StockReservation::query()->where('source_id', $preSale->id)->update(['status' => 'consumed', 'consumed_at' => now()]);
        $this->openCashRegister($business, $branch, $user);

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-picking-key');

        $this->assertSame(7.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(1, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'consumed')->count());
    }

    public function test_delivery_is_idempotent_and_does_not_create_a_second_sale_or_batch(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);

        $first = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-idempotency-key');
        $second = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-idempotency-key');

        $this->assertSame($first->resultId, $second->resultId);
        $this->assertTrue($second->replayed);
        $this->assertDatabaseCount('route_delivery_batches', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
    }

    public function test_automatic_all_dispatches_only_eligible_sales_when_the_operational_gate_is_enabled(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);
        $this->configureFelAvailability($business, $branch);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        config(['fel.route_automation_enabled' => true]);
        Queue::fake();

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-auto-fel-key');

        Queue::assertPushed(RoutePreSaleAutomaticFelJob::class, fn (RoutePreSaleAutomaticFelJob $job) => $job->preSaleId === $preSale->id);
    }

    public function test_automatic_all_keeps_eligible_sales_manual_when_the_operational_gate_is_disabled(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        config(['fel.route_automation_enabled' => false]);
        Queue::fake();

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-auto-fel-disabled-key');

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('route_delivery_batches', ['business_id' => $business->id, 'fel_automation_enabled' => false]);
    }

    public function test_automatic_all_does_not_dispatch_fel_for_an_ineligible_customer(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $preSale->update(['total' => 2500]);
        $this->openCashRegister($business, $branch, $user);
        $this->configureFelAvailability($business, $branch);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        config(['fel.route_automation_enabled' => true]);
        Queue::fake();

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-ineligible-auto-fel-key');

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', [
            'pre_sale_id' => $preSale->id,
            'fel_dispatch_status' => 'not_requested',
            'error_message' => 'Consumidor Final no puede certificarse por Q2,500.00 o más.',
        ]);
    }

    public function test_automatic_all_does_not_dispatch_when_fel_is_unavailable_and_records_a_safe_reason(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        config(['fel.route_automation_enabled' => true]);
        Queue::fake();

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-auto-fel-unavailable-key');

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', [
            'pre_sale_id' => $preSale->id,
            'fel_dispatch_status' => 'not_requested',
            'error_message' => 'FEL no configurado para certificación automática.',
        ]);
        $this->assertDatabaseCount('electronic_documents', 0);
    }

    public function test_required_fel_eligibility_blocks_the_whole_delivery_before_local_effects(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);
        $preSale->update(['total' => 2500]);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_require_fel_eligible_customer' => true]);

        try {
            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-required-eligibility-key');
            $this->fail('Expected an eligibility validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pre_sale', $exception->errors());
        }

        $this->assertDatabaseCount('route_delivery_batches', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
    }

    public function test_automatic_fel_job_certifies_the_existing_receipt_without_creating_operational_effects(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('invoice', 'cash');
        $this->openCashRegister($business, $branch, $user);
        $this->configureFelAvailability($business, $branch);
        config(['fel.route_automation_enabled' => true]);
        $delivery = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-auto-fel-certified-delivery-key');
        $sale = $preSale->refresh()->convertedSale()->firstOrFail();
        $stockBefore = (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock');
        $cashBefore = CashMovement::query()->where('business_id', $business->id)->count();

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $certifiedSale): ElectronicDocument {
            $document = $certifiedSale->electronicDocument()->firstOrFail();
            $document->update(['status' => 'certified', 'uuid' => 'automatic-route-fel-uuid']);
            $certifiedSale->update(['certification_status' => 'certified']);

            return $document->refresh();
        });
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->runAutomaticFelJob($preSale, $sale, $user, $business, $branch);

        $this->assertDatabaseHas('electronic_documents', ['sale_id' => $sale->id, 'status' => 'certified']);
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', ['route_delivery_batch_id' => $delivery->resultId, 'pre_sale_id' => $preSale->id, 'fel_dispatch_status' => 'certified']);
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, \App\Models\SalePayment::query()->where('sale_id', $sale->id)->count());
        $this->assertSame($cashBefore, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame($stockBefore, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->count());
    }

    public function test_automatic_fel_job_marks_failed_without_repeating_receipt_effects(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('invoice', 'cash');
        $this->openCashRegister($business, $branch, $user);
        $this->configureFelAvailability($business, $branch);
        config(['fel.route_automation_enabled' => true]);
        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-auto-fel-failed-delivery-key');
        $sale = $preSale->refresh()->convertedSale()->firstOrFail();
        $stockBefore = (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock');
        $cashBefore = CashMovement::query()->where('business_id', $business->id)->count();

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $failedSale): never {
            $failedSale->electronicDocument()->firstOrFail()->update(['status' => 'failed', 'error_message' => 'Digifact rechazó la solicitud.']);
            $failedSale->update(['certification_status' => 'failed']);

            throw new FelException('Digifact rechazó la solicitud.');
        });
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->runAutomaticFelJob($preSale, $sale, $user, $business, $branch);

        $this->assertDatabaseHas('electronic_documents', ['sale_id' => $sale->id, 'status' => 'failed']);
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', ['pre_sale_id' => $preSale->id, 'fel_dispatch_status' => 'failed']);
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, \App\Models\SalePayment::query()->where('sale_id', $sale->id)->count());
        $this->assertSame($cashBefore, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame($stockBefore, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->count());
    }

    public function test_automatic_fel_job_marks_unknown_creates_reconciliation_and_does_not_retry_it(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);
        $this->configureFelAvailability($business, $branch);
        config(['fel.route_automation_enabled' => true]);
        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-auto-fel-unknown-delivery-key');
        $sale = $preSale->refresh()->convertedSale()->firstOrFail();

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $unknownSale): never {
            $unknownSale->electronicDocument()->firstOrFail()->update(['status' => 'unknown', 'error_message' => 'Tiempo de espera agotado.']);
            $unknownSale->update(['certification_status' => 'unknown']);

            throw new FelException('Tiempo de espera agotado.');
        });
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->runAutomaticFelJob($preSale, $sale, $user, $business, $branch);

        $this->assertDatabaseHas('electronic_documents', ['sale_id' => $sale->id, 'status' => 'unknown']);
        $this->assertDatabaseHas('fel_reconciliation_requests', ['business_id' => $business->id, 'sale_id' => $sale->id, 'status' => 'pending']);
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', ['pre_sale_id' => $preSale->id, 'fel_dispatch_status' => 'unknown']);

        $retry = Mockery::mock(DigifactInvoiceService::class);
        $retry->shouldReceive('certifySale')->never();
        $this->app->instance(DigifactInvoiceService::class, $retry);

        $this->runAutomaticFelJob($preSale->fresh(), $sale->fresh(), $user, $business, $branch);

        $this->assertSame(1, FelReconciliationRequest::query()->where('sale_id', $sale->id)->count());
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
    }

    public function test_automatic_fel_job_revalidates_unavailable_fel_without_creating_an_electronic_document(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);
        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-auto-fel-revalidate-unavailable-key');
        $sale = $preSale->refresh()->convertedSale()->firstOrFail();
        config(['fel.route_automation_enabled' => true]);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->never();
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->runAutomaticFelJob($preSale, $sale, $user, $business, $branch);

        $this->assertDatabaseMissing('electronic_documents', ['sale_id' => $sale->id]);
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', [
            'pre_sale_id' => $preSale->id,
            'sale_id' => $sale->id,
            'fel_dispatch_status' => 'not_requested',
            'error_message' => 'FEL no configurado para certificación automática.',
        ]);
    }

    public function test_automatic_fel_job_does_not_retry_a_failed_document(): void
    {
        [$business, $branch, $user, $preSale] = $this->pickedPreSale('invoice', 'card');
        $this->openCashRegister($business, $branch, $user);
        $this->configureFelAvailability($business, $branch);
        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-auto-fel-no-failed-retry-key');
        $sale = $preSale->refresh()->convertedSale()->firstOrFail();
        $document = ElectronicDocument::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'status' => 'failed',
            'error_message' => 'Digifact rechazó la solicitud.',
            'created_by' => $user->id,
        ]);
        $sale->update(['electronic_document_id' => $document->id, 'certification_status' => 'failed']);
        config(['fel.route_automation_enabled' => true]);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->never();
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->runAutomaticFelJob($preSale, $sale, $user, $business, $branch);

        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'failed']);
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', ['pre_sale_id' => $preSale->id, 'fel_dispatch_status' => 'failed']);
    }

    /** @param array<string, mixed> $overrides */
    private function insertPolicySnapshotBatch(Business $business, $branch, User $user, PreSale $preSale, array $overrides = []): int
    {
        return DB::table('route_delivery_batches')->insertGetId([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $preSale->route_work_day_id,
            'route_zone_id' => $preSale->route_zone_id,
            'delivered_by' => $user->id,
            'status' => 'processing',
            'stock_deduction_timing' => 'invoice',
            'invoicing_mode' => 'manual',
            'fel_automation_enabled' => false,
            'collection_workflow_mode_snapshot' => null,
            'allowed_payment_methods_snapshot' => null,
            'primary_payment_method_snapshot' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function additionalPickedPreSale(PreSale $source, User $user, ?string $paymentMethod): PreSale
    {
        $product = $source->items()->firstOrFail()->product;
        $customer = Customer::query()->create([
            'business_id' => $source->business_id,
            'name' => 'Cliente adicional '.uniqid(),
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
        ]);
        $preSale = PreSale::query()->create([
            'business_id' => $source->business_id,
            'branch_id' => $source->branch_id,
            'route_work_day_id' => $source->route_work_day_id,
            'route_zone_id' => $source->route_zone_id,
            'customer_id' => $customer->id,
            'seller_id' => $source->seller_id,
            'status' => PreSale::STATUS_PICKED,
            'subtotal' => 20,
            'discount_total' => 0,
            'total' => 20,
            'payment_method' => $paymentMethod,
            'agreed_payment_method' => $paymentMethod,
            'picked_at' => now(),
            'picked_by' => $user->id,
        ]);
        $item = PreSaleItem::query()->create([
            'business_id' => $source->business_id,
            'pre_sale_id' => $preSale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'picked_quantity' => 1,
            'unit_price' => 20,
            'original_price' => 20,
            'discount' => 0,
            'total' => 20,
        ]);
        StockReservation::query()->create([
            'business_id' => $source->business_id,
            'branch_id' => $source->branch_id,
            'product_id' => $product->id,
            'source_type' => 'pre_sale',
            'source_id' => $preSale->id,
            'source_item_id' => $item->id,
            'quantity' => 1,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return $preSale;
    }

    private function pickedPreSale(string $timing, ?string $paymentMethod): array
    {
        $business = Business::query()->create([
            'name' => 'Delivery '.uniqid(),
            'slug' => 'delivery-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($user, 'owner');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'allow_receipts' => true, 'allow_invoices' => true, 'route_pre_sale_stock_deduction_timing' => $timing]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'cash_register', 'is_enabled' => true, 'enabled_at' => now()]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $user->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $user->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF', 'country' => 'GT']);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'DEL-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => PreSale::STATUS_PICKED, 'subtotal' => 60, 'discount_total' => 0, 'total' => 60, 'payment_method' => $paymentMethod, 'agreed_payment_method' => $paymentMethod, 'picked_at' => now(), 'picked_by' => $user->id]);
        $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'picked_quantity' => 3, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 60]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $user->id]);

        return [$business, $branch, $user, $preSale->load('workDay'), $product];
    }

    private function openCashRegister(Business $business, $branch, User $user): void
    {
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        if (TenantSetting::query()->where('business_id', $business->id)->value('route_collection_responsibility') === 'delivery_agent') {
            return;
        }
        PreSale::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('seller_id', $user->id)->where('status', PreSale::STATUS_PICKED)->whereNotNull('agreed_payment_method')->each(function (PreSale $preSale) use ($user) {
            app(RoutePreSaleCollectionService::class)->capture($preSale, ['amount' => $preSale->total, 'payment_method' => $preSale->agreed_payment_method, 'idempotency_key' => 'fixture-collection-'.$preSale->id], $user);
        });
    }

    private function openEmptyCashRegister(Business $business, $branch, User $user): CashRegisterSession
    {
        return CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    private function setPreSaleTotal(PreSale $preSale, float $total): void
    {
        $item = $preSale->items()->sole();
        $item->update([
            'quantity' => 1,
            'picked_quantity' => 1,
            'unit_price' => $total,
            'original_price' => $total,
            'total' => $total,
        ]);
        StockReservation::query()->where('source_item_id', $item->id)->update(['quantity' => 1]);
        $preSale->update(['subtotal' => $total, 'total' => $total]);
    }

    private function assertImmediatePaidCreatingFailureRollsBack(string $model, PreSale $preSale, User $user, string $idempotencyKey): void
    {
        $model::creating(function (): void {
            throw new \RuntimeException('Intentional Task 6 persistence failure.');
        });

        try {
            app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, $idempotencyKey);
            $this->fail('Expected the immediate paid child transaction to roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Intentional Task 6 persistence failure.', $exception->getMessage());
        } finally {
            $model::flushEventListeners();
        }
    }

    private function configureFelAvailability(Business $business, $branch): void
    {
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'fel_gt', 'is_enabled' => true, 'enabled_at' => now()]);
        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'secret',
            'test_base_url' => 'https://testnucgt.digifact.com/api',
            'affiliate_type' => 'GEN',
        ]);
        TenantFelPhrase::query()->create([
            'business_id' => $business->id,
            'tenant_fel_setting_id' => $settings->id,
            'data_identifier' => '1',
            'phrase_type' => '1',
            'scenario_code' => '2',
            'type_data' => '1',
            'type_value' => '1',
            'scenario_data' => '1',
            'scenario_value' => '2',
        ]);
        $branch->update([
            'fel_establishment_code' => '1',
            'fel_establishment_name' => 'Casa Matriz',
            'fel_address' => 'Ciudad',
            'fel_postal_code' => '01001',
            'fel_municipality' => 'Guatemala',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);
    }

    private function runAutomaticFelJob(PreSale $preSale, Sale $sale, User $user, Business $business, $branch): void
    {
        $job = new RoutePreSaleAutomaticFelJob($preSale->id, $sale->id, $business->id, $branch->id, $user->id);

        $this->app->call([$job, 'handle']);
    }
}
