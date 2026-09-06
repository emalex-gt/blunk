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
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Routes\RouteDeliveryBatchService;
use App\Services\Routes\RoutePreSaleCollectionService;
use App\Jobs\RoutePreSaleAutomaticFelJob;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
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
