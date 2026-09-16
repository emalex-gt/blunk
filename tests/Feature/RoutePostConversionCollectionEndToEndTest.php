<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteDeliveryBatch;
use App\Models\RoutePostConversionCollection;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\SalePayment;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Services\Routes\RouteCashSettlementEligibility;
use App\Services\Routes\RouteGlobalOperationsService;
use App\Services\Routes\RoutePostConversionCollectionReader;
use App\Services\Routes\RoutePostConversionCollectionReversalService;
use App\Services\Routes\RoutePostConversionCollectionService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoutePostConversionCollectionEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_per_order_pre_seller_completes_the_global_generation_collection_reversal_and_recollection_lifecycle(): void
    {
        [$business, $branch, $admin, $seller, $preSale, $preparationSession] = $this->submittedRoutePreSale();
        $settings = app(RouteBranchCollectionSettingsService::class);
        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash', 'transfer'],
            'primary_payment_method' => 'cash',
        ]);

        $global = app(RouteGlobalOperationsService::class);
        $global->prepareAll($admin, 'task-7d-prepare-0001');
        $this->assertSame(PreSale::STATUS_PICKED, $preSale->fresh()->status);
        $preparationSession->update(['status' => 'closed', 'closed_at' => now()]);

        $preview = $global->salesPreview($business->id, $branch->id);
        $this->assertSame([], array_values(array_filter($preview['blocked'], fn (array $block) => $block['reason_code'] === 'pre_seller_post_conversion_collection_unavailable')));
        $this->assertSame(1, $preview['summary']['sales_eligible_count']);

        $generated = $global->generateSales($admin, 'task-7d-generate-0001');
        $this->assertCount(1, $generated['processed']);
        $this->assertSame([], $generated['blocked']);
        $this->assertSame([], $generated['failed']);
        $this->assertCount(1, $global->generateSales($admin, 'task-7d-generate-0001')['processed']);

        $batch = RouteDeliveryBatch::query()->where('business_id', $business->id)->sole();
        $entry = $batch->preSales()->sole();
        $sale = $preSale->fresh()->convertedSale()->firstOrFail();
        $this->assertSame('per_order_collection', $batch->collection_workflow_mode_snapshot);
        $this->assertSame('pre_seller', $batch->collection_responsibility_snapshot);
        $this->assertSame(['cash', 'transfer'], $batch->allowed_payment_methods_snapshot);
        $this->assertSame('cash', $batch->primary_payment_method_snapshot);
        $this->assertSame('cash', $entry->agreed_payment_method_snapshot);
        $this->assertSame('cash', $entry->payment_method);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame(0.0, (float) $sale->amount_paid);
        $this->assertSame(0.0, (float) $sale->credit_balance);
        $this->assertFalse((bool) $sale->is_credit_sale);
        $this->assertNull($sale->due_date);
        $this->assertSame(0, SalePayment::query()->where('sale_id', $sale->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertDatabaseCount('route_post_conversion_collections', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);

        $this->assertSame([$entry->id], app(RoutePostConversionCollectionReader::class)->pendingForSeller($seller)->pluck('entry_id')->all());
        $collector = app(RoutePostConversionCollectionService::class);
        $captured = $collector->collect($entry, ['payment_method' => 'transfer'], $seller, 'task-7d-collect-transfer-0001');
        $this->assertFalse($captured->replayed);
        $this->assertTrue($collector->collect($entry, ['payment_method' => 'transfer'], $seller, 'task-7d-collect-transfer-0001')->replayed);

        $first = RoutePostConversionCollection::query()->findOrFail($captured->resultId);
        $this->assertSame('captured', $first->status);
        $this->assertSame('transfer', $first->payment_method);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame('transfer', $entry->fresh()->payment_method);
        $this->assertSame('cash', $entry->fresh()->agreed_payment_method_snapshot);
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale->id)->where('status', 'captured')->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());

        $reversals = app(RoutePostConversionCollectionReversalService::class);
        $reverseData = ['reason_code' => 'wrong_amount', 'explanation' => 'Corrección de prueba.', 'confirmed' => true];
        $reversed = $reversals->reverse($first, $reverseData, $admin, 'task-7d-reverse-0001');
        $this->assertFalse($reversed->replayed);
        $this->assertTrue($reversals->reverse($first, $reverseData, $admin, 'task-7d-reverse-0001')->replayed);
        $this->assertSame('reversed', $first->fresh()->status);
        $this->assertSame('unpaid', $sale->fresh()->payment_status);
        $this->assertSame('cash', $entry->fresh()->payment_method);
        $this->assertSame('cash', $entry->fresh()->agreed_payment_method_snapshot);
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale->id)->where('status', 'reversed')->count());

        $settings->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer', 'card'],
            'primary_payment_method' => 'transfer',
        ]);
        try {
            $collector->collect($entry->fresh(), ['payment_method' => 'cash'], $seller, 'task-7d-recollect-cash-0001');
            $this->fail('Expected the live policy to reject cash after the policy change.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_method', $exception->errors());
        }
        $second = $collector->collect($entry->fresh(), ['payment_method' => 'card'], $seller, 'task-7d-recollect-card-0001');
        $this->assertFalse($second->replayed);
        $this->assertSame(2, RoutePostConversionCollection::query()->where('sale_id', $sale->id)->count());
        $this->assertSame('reversed', $first->fresh()->status);
        $this->assertSame('captured', RoutePostConversionCollection::query()->findOrFail($second->resultId)->status);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame('card', $entry->fresh()->payment_method);
        $this->assertSame('cash', $entry->fresh()->agreed_payment_method_snapshot);
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale->id)->where('status', 'captured')->count());
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale->id)->where('status', 'reversed')->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_per_order_pre_seller_generates_without_cash_and_held_cash_collection_is_settlement_eligible(): void
    {
        [$business, $branch, $admin, $seller, $preSale, $preparationSession] = $this->submittedRoutePreSale();
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);

        $global = app(RouteGlobalOperationsService::class);
        $global->prepareAll($admin, 'task-7d-cash-prepare-0001');
        $preparationSession->update(['status' => 'closed', 'closed_at' => now()]);
        $global->generateSales($admin, 'task-7d-cash-generate-0001');

        $entry = RouteDeliveryBatch::query()->where('business_id', $business->id)->sole()->preSales()->sole();
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'task-7d-cash-collect-0001');
        $collection = RoutePostConversionCollection::query()->findOrFail($captured->resultId);

        $this->assertSame('held_by_collector', $collection->custody_status);
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame([$collection->id], app(RouteCashSettlementEligibility::class)->forCollector($business->id, $branch->id, $seller->id)
            ->where('origin', 'route_post_conversion_collection')->pluck('collection_id')->all());
    }

    /** @return array{Business, \App\Models\Branch, User, User, PreSale, CashRegisterSession} */
    private function submittedRoutePreSale(): array
    {
        $business = Business::query()->create(['name' => 'Task 7D '.uniqid(), 'slug' => 'task-7d-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        TenantSetting::query()->create(['business_id' => $business->id, 'route_pre_sale_stock_deduction_timing' => 'picking', 'route_pre_sale_invoicing_mode' => 'manual', 'route_cash_custody_policy' => 'collector_custody_until_settlement']);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'cash_register', 'is_enabled' => true, 'enabled_at' => now()]);
        $admin = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'owner', 'is_active' => true]);
        $seller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'pre_seller', 'is_active' => true]);
        Permissions::assignRole($admin, 'owner');
        Permissions::assignRole($seller, 'pre_seller');
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $admin->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $seller->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $seller->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF', 'country' => 'GT']);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'T7D-'.uniqid(), 'cost_price' => 10, 'sale_price' => 123.47, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => PreSale::STATUS_SUBMITTED, 'subtotal' => 123.47, 'discount_total' => 0, 'total' => 123.47, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash']);
        $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 123.47, 'original_price' => 123.47, 'discount' => 0, 'total' => 123.47]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => 1, 'status' => 'active', 'created_by' => $seller->id]);

        return [$business, $branch, $admin->fresh(), $seller->fresh(), $preSale, $session];
    }
}
