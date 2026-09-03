<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteDeliveryBatchService;
use App\Jobs\RoutePreSaleAutomaticFelJob;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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

    public function test_deliver_all_creates_paid_receipt_payment_and_cash_movement_for_picked_cash_pre_sale(): void
    {
        [$business, $branch, $user, $preSale, $product] = $this->pickedPreSale('invoice', 'cash');
        $this->openCashRegister($business, $branch, $user);

        $result = app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-batch-cash-key');

        $this->assertDatabaseHas('route_delivery_batches', ['id' => $result->resultId, 'business_id' => $business->id, 'status' => 'completed']);
        $this->assertDatabaseHas('sales', ['id' => $preSale->refresh()->converted_sale_id, 'document_type' => 'receipt', 'payment_status' => 'paid', 'payment_method' => 'cash']);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $preSale->converted_sale_id, 'method' => 'cash', 'amount' => 60]);
        $this->assertDatabaseHas('cash_movements', ['business_id' => $business->id, 'type' => 'sale_cash', 'amount' => 60]);
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
            $this->assertArrayHasKey('payment_method', $exception->errors());
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
        $this->openCashRegister($business, $branch, $user);
        $preSale->update(['total' => 2500]);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        config(['fel.route_automation_enabled' => true]);
        Queue::fake();

        app(RouteDeliveryBatchService::class)->deliverAll($preSale->workDay, $user, 'route-delivery-ineligible-auto-fel-key');

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('route_delivery_batch_pre_sales', ['pre_sale_id' => $preSale->id, 'fel_dispatch_status' => 'not_requested']);
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
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'allow_receipts' => true, 'route_pre_sale_stock_deduction_timing' => $timing]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'cash_register', 'is_enabled' => true, 'enabled_at' => now()]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $user->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $user->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF', 'country' => 'GT']);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'DEL-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => PreSale::STATUS_PICKED, 'subtotal' => 60, 'discount_total' => 0, 'total' => 60, 'payment_method' => $paymentMethod, 'picked_at' => now(), 'picked_by' => $user->id]);
        $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'picked_quantity' => 3, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 60]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $user->id]);

        return [$business, $branch, $user, $preSale->load('workDay'), $product];
    }

    private function openCashRegister(Business $business, $branch, User $user): void
    {
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
    }
}
