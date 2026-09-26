<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteDeliveryStop;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteDeliveryRunAssignmentService;
use App\Services\Routes\RouteDeliveryRunService;
use App\Services\Routes\RouteDeliveryStopCorrectionService;
use App\Services\Routes\RouteDeliveryStopService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RouteDeliveryRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_delivered_unpaid_in_app_stop_can_be_returned_without_changing_original_stop(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries();
        $entry = $entries[0];
        $run = app(RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entry->id]);
        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'phase2a-in-app-start');
        $stop = $run->fresh()->stops()->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'delivered', 'collected' => false], $deliveryUser, 'phase2a-in-app-delivered');

        $result = app(\App\Services\Routes\RouteOperationReturnService::class)->completeStop($stop->fresh(), [
            'idempotency_key' => 'phase2a-in-app-return', 'reason' => 'Mercancía recibida de vuelta', 'goods_received' => true,
        ], $manager);

        $this->assertDatabaseHas('route_operation_returns', ['id' => $result->resultId, 'sale_id' => $entry->sale_id, 'route_delivery_stop_id' => $stop->id, 'route_external_delivery_reconciliation_item_id' => null, 'status' => 'completed']);
        $this->assertDatabaseHas('route_delivery_stops', ['id' => $stop->id, 'status' => 'delivered']);
        $this->assertDatabaseHas('sales', ['id' => $entry->sale_id, 'status' => 'cancelled', 'payment_status' => 'unpaid']);
        $this->assertDatabaseHas('route_pending_collection_cases', ['sale_id' => $entry->sale_id, 'status' => 'not_applicable']);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(0, app(RouteDeliveryRunService::class)->progress($run->fresh())['unpaid_delivered_count']);
        try {
            app(RouteDeliveryStopService::class)->collect($stop->fresh(), [
                'amount' => 60, 'payment_method' => 'transfer', 'collected_by' => $deliveryUser->id, 'collected_at' => now()->toDateTimeString(),
            ], $deliveryUser, 'phase2a-in-app-late-collect');
            $this->fail('A returned, cancelled sale cannot be collected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
    }

    public function test_in_app_return_http_preserves_stop_and_exposes_return_in_detail(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries();
        $entry = $entries[0];
        $run = app(RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entry->id]);
        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'phase2a-stop-http-start');
        $stop = $run->fresh()->stops()->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'delivered', 'collected' => false], $deliveryUser, 'phase2a-stop-http-delivered');
        $this->actingAs($manager)->withSession(['active_business_id' => $manager->business_id])
            ->get(route('routes.delivery-stops.show', $stop))->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Routes/Mobile/DeliveryRuns/Stop')
                ->where('can_return', true));
        $this->actingAs($manager)->withSession(['active_business_id' => $manager->business_id])
            ->post(route('routes.delivery-stops.return', $stop), [
                'idempotency_key' => 'phase2a-stop-http-return', 'reason' => 'Devolución aceptada', 'goods_received' => true,
            ])->assertSessionHasNoErrors();
        $this->actingAs($manager)->withSession(['active_business_id' => $manager->business_id])
            ->get(route('routes.delivery-stops.show', $stop))->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Routes/Mobile/DeliveryRuns/Stop')
                ->where('stop.status', 'delivered')
                ->where('stop.operation_return.status', 'completed'));
    }

    public function test_simple_stop_correction_cannot_disguise_a_delivered_operation_as_non_delivery(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries();
        $run = app(RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entries[0]->id]);
        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'phase2a-correction-start');
        $stop = $run->fresh()->stops()->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'delivered', 'collected' => false], $deliveryUser, 'phase2a-correction-delivered');

        try {
            app(RouteDeliveryStopCorrectionService::class)->correct($stop->fresh(), [
                'delivery_status' => 'not_delivered', 'not_delivered_reason_code' => 'customer_absent', 'correction_reason' => 'Intento de anulación simple',
            ], $manager);
            $this->fail('Expected terminal correction to require its own orchestration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction', $exception->errors());
        }
        $this->assertSame('delivered', $stop->fresh()->status);
        $this->assertSame('completed', $entries[0]->sale->fresh()->status);
    }

    public function test_draft_can_be_reordered_then_started_and_closed_with_an_unpaid_warning(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries(2);
        $assignments = app(RouteDeliveryRunAssignmentService::class);
        $run = $assignments->createDraft($manager, $deliveryUser, collect($entries)->pluck('id')->all());

        $stopIds = $run->stops()->orderByDesc('id')->pluck('id')->all();
        $assignments->reorder($run, $stopIds, $manager);
        $this->assertSame($stopIds, $run->fresh()->stops()->orderBy('position')->pluck('id')->all());

        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'in-app-run-start-0001');
        $stop = $run->fresh()->stops()->orderBy('position')->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($stop, [
            'delivery_status' => 'delivered', 'delivery_notes' => 'Recibió recepción.', 'collected' => false,
        ], $deliveryUser, 'in-app-stop-result-0001');
        $other = $run->fresh()->stops()->where('id', '!=', $stop->id)->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($other, [
            'delivery_status' => 'not_delivered', 'not_delivered_reason_code' => 'customer_absent', 'collected' => false,
        ], $deliveryUser, 'in-app-stop-result-0002');

        try {
            app(RouteDeliveryRunService::class)->close($run->fresh(), $deliveryUser, 'in-app-run-close-0001', false);
            $this->fail('Expected unpaid-close confirmation validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_unpaid', $exception->errors());
        }
        $closed = app(RouteDeliveryRunService::class)->close($run->fresh(), $deliveryUser, 'in-app-run-close-0002', true);

        $this->assertFalse($closed->replayed);
        $this->assertDatabaseHas('route_delivery_runs', ['id' => $run->id, 'status' => 'closed', 'closed_by' => $deliveryUser->id]);
        $this->assertDatabaseHas('route_delivery_stops', ['id' => $stop->id, 'status' => 'delivered', 'delivery_notes' => 'Recibió recepción.', 'not_delivered_reason_code' => null]);
    }

    public function test_delivery_agent_can_collect_after_result_once_without_accounts_receivable(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries();
        $run = app(RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entries[0]->id]);
        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'in-app-collect-start-0001');
        $stop = $run->fresh()->stops()->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'delivered', 'collected' => false], $deliveryUser, 'in-app-collect-outcome-0001');

        $first = app(RouteDeliveryStopService::class)->collect($stop->fresh(), [
            'amount' => 60, 'payment_method' => 'transfer', 'collected_by' => $deliveryUser->id, 'collected_at' => now()->toDateTimeString(),
        ], $deliveryUser, 'in-app-collect-payment-0001');
        $second = app(RouteDeliveryStopService::class)->collect($stop->fresh(), [
            'amount' => 60, 'payment_method' => 'transfer', 'collected_by' => $deliveryUser->id, 'collected_at' => now()->toDateTimeString(),
        ], $deliveryUser, 'in-app-collect-payment-0001');

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertDatabaseHas('route_delivery_collections', ['route_delivery_stop_id' => $stop->id, 'delivery_origin' => 'in_app_stop', 'payment_method' => 'transfer', 'custody_status' => 'not_applicable']);
        $this->assertDatabaseHas('sales', ['id' => $stop->sale_id, 'payment_status' => 'paid', 'amount_paid' => 60]);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_not_delivered_stop_cannot_collect_during_or_after_completion(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries();
        $run = app(RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entries[0]->id]);
        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'in-app-terminal-start-0001');
        $stop = $run->fresh()->stops()->firstOrFail();
        $payment = ['amount' => 60, 'payment_method' => 'transfer', 'collected_by' => $deliveryUser->id, 'collected_at' => now()->toDateTimeString()];

        try {
            app(RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'not_delivered', 'not_delivered_reason_code' => 'customer_absent', 'collected' => true, ...$payment], $deliveryUser, 'in-app-terminal-invalid-0001');
            $this->fail('Expected not-delivered payment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collected', $exception->errors());
        }
        $this->assertSame('pending', $stop->fresh()->status);

        app(RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'not_delivered', 'not_delivered_reason_code' => 'customer_absent', 'collected' => false], $deliveryUser, 'in-app-terminal-valid-0001');
        try {
            app(RouteDeliveryStopService::class)->collect($stop->fresh(), $payment, $deliveryUser, 'in-app-terminal-late-0001');
            $this->fail('Expected post-result payment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stop', $exception->errors());
        }
        $this->assertDatabaseCount('route_delivery_collections', 0);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_assignment_rejects_external_and_duplicate_entries(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries();
        $assignments = app(RouteDeliveryRunAssignmentService::class);
        $run = $assignments->createDraft($manager, $deliveryUser, [$entries[0]->id]);

        $this->expectException(ValidationException::class);
        $assignments->assignEntries($run, [$entries[0]->id], $manager);
    }

    public function test_correction_is_append_only_and_does_not_accept_financial_fields(): void
    {
        [$manager, $deliveryUser, $entries] = $this->inAppEntries();
        $run = app(RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entries[0]->id]);
        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'in-app-correct-start-0001');
        $stop = $run->fresh()->stops()->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'not_delivered', 'not_delivered_reason_code' => 'other', 'delivery_notes' => 'Dirección cerrada', 'collected' => false], $deliveryUser, 'in-app-correct-outcome-0001');

        app(RouteDeliveryStopCorrectionService::class)->correct($stop->fresh(), ['delivery_status' => 'delivered', 'delivery_notes' => 'Recibió encargado', 'correction_reason' => 'Confirmación presencial'], $manager);
        $this->assertDatabaseHas('route_delivery_stop_revisions', ['route_delivery_stop_id' => $stop->id, 'version' => 1, 'corrected_by' => $manager->id]);
        $this->assertDatabaseHas('route_delivery_stops', ['id' => $stop->id, 'status' => 'delivered', 'not_delivered_reason_code' => null, 'delivery_notes' => 'Recibió encargado']);

        $this->expectException(ValidationException::class);
        app(RouteDeliveryStopCorrectionService::class)->correct($stop->fresh(), ['delivery_status' => 'delivered', 'correction_reason' => 'Intento inválido', 'amount' => 1], $manager);
    }

    /** @return array{0: User, 1: User, 2: array<int, RouteDeliveryBatchPreSale>} */
    private function inAppEntries(int $count = 1): array
    {
        $business = Business::query()->create(['name' => 'In-app '.uniqid(), 'slug' => 'in-app-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $manager = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $deliveryUser = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($manager, 'owner');
        Permissions::assignRole($deliveryUser, 'delivery_agent');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'allow_receipts' => true, 'allow_invoices' => true, 'route_collection_responsibility' => 'delivery_agent', 'route_delivery_tracking' => 'in_app']);
        foreach (['routes', 'cash_register'] as $module) TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $manager->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $manager->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'IAP-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 20, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 20]);
        for ($index = 0; $index < $count; $index++) {
            $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF'.$index, 'country' => 'GT']);
            $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $manager->id, 'status' => PreSale::STATUS_PICKED, 'subtotal' => 60, 'discount_total' => 0, 'total' => 60, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash', 'picked_at' => now(), 'picked_by' => $manager->id]);
            $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'picked_quantity' => 3, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 60]);
            StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $manager->id]);
        }
        \App\Models\CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $manager->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $delivery = app(\App\Services\Routes\RouteDeliveryBatchService::class)->deliverAll($workDay, $manager, 'in-app-fixture-'.uniqid());
        $entries = RouteDeliveryBatchPreSale::query()->with(['batch', 'sale'])->where('route_delivery_batch_id', $delivery->resultId)->orderBy('id')->get()->all();
        return [$manager, $deliveryUser, $entries];
    }
}
