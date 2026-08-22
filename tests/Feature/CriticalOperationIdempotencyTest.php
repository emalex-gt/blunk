<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\CreditCustomerTransfer;
use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\InventoryTransfer;
use App\Models\PreSale;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Purchase;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\RouteZoneCustomer;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CriticalOperationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        Permissions::syncDefaults();
    }

    public function test_purchase_store_replays_same_idempotency_key_without_duplicate_purchase_or_stock(): void
    {
        [$business, $user, $branch] = $this->tenant('owner', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor idem']);
        $product = $this->product($business, $branch, stock: 0, salePrice: 20);
        $payload = [
            'idempotency_key' => 'idem-purchase-1',
            'supplier_id' => $supplier->id,
            'payment_method' => 'card',
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 15],
            ],
        ];

        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.index'));
        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.index'));

        $this->assertSame(1, Purchase::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'purchase')->count());
        $this->assertSame(2.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('stock'));
    }

    public function test_InventoryTransfer_replays_same_idempotency_key_without_duplicate_transfer_or_stock_moves(): void
    {
        [$business, $user, $from] = $this->tenant('owner', ['branches', 'inventory'], useBranches: true);
        $to = Branch::query()->create(['business_id' => $business->id, 'name' => 'Destino', 'code' => 'DST', 'is_active' => true]);
        $product = $this->product($business, $from, stock: 5, salePrice: 20);
        $payload = [
            'idempotency_key' => 'idem-transfer-1',
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ];

        $this->actingAs($user)->post(route('inventory.transfers.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('inventory.transfers.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, InventoryTransfer::query()->where('business_id', $business->id)->count());
        $this->assertSame(2, StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(3.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $from->id)->value('stock'));
        $this->assertSame(2.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $to->id)->value('stock'));
    }

    public function test_stock_adjustment_replays_same_idempotency_key_without_double_adjustment(): void
    {
        [$business, $user, $branch] = $this->tenant('stock_manager', ['inventory']);
        $product = $this->product($business, $branch, stock: 1, salePrice: 20);
        $payload = [
            'idempotency_key' => 'idem-stock-1',
            'product_id' => $product->id,
            'type' => 'increase',
            'quantity' => 4,
            'note' => 'Ajuste idempotente',
        ];

        $this->actingAs($user)->postJson(route('stock.adjustments.store'), $payload)->assertOk();
        $this->actingAs($user)->postJson(route('stock.adjustments.store'), $payload)->assertOk();

        $this->assertSame(5.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('stock'));
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('type', 'entry')->count());
    }

    public function test_AccountsReceivable_payment_replays_same_idempotency_key_without_duplicate_payment_or_balance_change(): void
    {
        [$business, $user, $branch] = $this->tenant('owner', ['credits'], enableCredits: true);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente CxC',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'customer_id' => $customer->id,
            'current_balance' => 100,
        ]);
        $payload = [
            'idempotency_key' => 'idem-ar-payment-1',
            'customer_id' => $customer->id,
            'amount' => 25,
            'payment_method' => 'bank_transfer',
        ];

        $this->actingAs($user)->post(route('credits.payments.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('credits.payments.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, CustomerCreditPayment::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, CustomerAccountMovement::query()->where('business_id', $business->id)->where('type', 'payment')->count());
        $this->assertSame('75.00', CustomerCreditAccount::query()->where('customer_id', $customer->id)->value('current_balance'));
        $this->assertSame($branch->id, CustomerCreditPayment::query()->firstOrFail()->branch_id);
    }

    public function test_credit_payment_cancel_blocks_a_second_distinct_key_without_reversing_balance_twice(): void
    {
        [$business, $user, $branch] = $this->tenant('owner', ['credits'], enableCredits: true);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente cancelación CxC',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'customer_id' => $customer->id,
            'current_balance' => 100,
        ]);

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'idempotency_key' => 'idem-ar-payment-to-cancel',
            'customer_id' => $customer->id,
            'amount' => 25,
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();

        $payment = CustomerCreditPayment::query()->firstOrFail();

        $this->actingAs($user)->post(route('credits.payments.cancel', $payment), [
            'idempotency_key' => 'idem-ar-payment-cancel-1',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('credits.payments.cancel', $payment), [
            'idempotency_key' => 'idem-ar-payment-cancel-2',
        ])->assertSessionHasErrors('payment');

        $this->assertSame('cancelled', $payment->refresh()->status);
        $this->assertSame('100.00', CustomerCreditAccount::query()->where('customer_id', $customer->id)->value('current_balance'));
        $this->assertSame(1, CustomerAccountMovement::query()
            ->where('business_id', $business->id)
            ->where('payment_id', $payment->id)
            ->where('type', 'cancellation')
            ->count());
    }

    public function test_credit_receipt_line_cancel_blocks_second_distinct_key_without_double_cancelling_quantity(): void
    {
        [$business, $user, $branch] = $this->tenant('owner', ['credits'], enableCreditReservations: true);
        $product = $this->product($business, $branch, stock: 5, salePrice: 20);
        $line = $this->creditReservation($business, $branch, $product, 2);

        $this->actingAs($user)->delete(route('credits.lines.cancel', $line), [
            'idempotency_key' => 'idem-credit-line-cancel-1',
            'reason' => 'Duplicada',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->delete(route('credits.lines.cancel', $line), [
            'idempotency_key' => 'idem-credit-line-cancel-2',
            'reason' => 'Duplicada',
        ])->assertSessionHasErrors('line');

        $line->refresh();
        $this->assertSame(2, (int) $line->qty_cancelled);
        $this->assertSame(0, (int) $line->qty_pending);
    }

    public function test_credit_customer_transfer_blocks_second_distinct_key_without_empty_transfer_record(): void
    {
        [$business, $user, $branch] = $this->tenant('owner', ['credits'], enableCreditReservations: true);
        $from = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente origen',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'country' => 'GT',
        ]);
        $to = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente destino',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'country' => 'GT',
        ]);
        $product = $this->product($business, $branch, stock: 5, salePrice: 20);
        $this->creditReservation($business, $branch, $product, 2, $from);

        $this->actingAs($user)->post(route('credits.customers.transfer', $from), [
            'idempotency_key' => 'idem-credit-transfer-1',
            'to_customer_doc_number' => $to->doc_number,
            'reason' => 'Cliente duplicado',
        ])->assertRedirect(route('credits.customers.show', $to));

        $this->actingAs($user)->post(route('credits.customers.transfer', $from), [
            'idempotency_key' => 'idem-credit-transfer-2',
            'to_customer_doc_number' => $to->doc_number,
            'reason' => 'Cliente duplicado',
        ])->assertSessionHasErrors('customer');

        $this->assertSame(1, CreditCustomerTransfer::query()->where('business_id', $business->id)->count());
        $this->assertSame($to->id, CreditReceipt::query()->where('business_id', $business->id)->value('customer_id'));
    }

    public function test_route_pre_sale_replays_same_idempotency_key_without_duplicate_items_or_reservations(): void
    {
        [$business, $seller, $branch] = $this->tenant('pre_seller', ['routes'], reservePreSaleStock: true);
        $zone = RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => 'Ruta idem',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente ruta']);
        RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);
        $workDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'status' => 'open',
            'work_date' => now()->toDateString(),
            'started_at' => now(),
        ]);
        $visit = RouteVisit::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_zone_id' => $zone->id,
            'route_zone_customer_id' => RouteZoneCustomer::query()->first()->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'visit_order' => 1,
            'status' => 'pending',
        ]);
        $product = $this->product($business, $branch, stock: 5, salePrice: 20);
        $payload = [
            'idempotency_key' => 'idem-route-pre-sale-1',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ];

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), $payload)->assertSessionHasNoErrors();
        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, PreSale::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(2.0, (float) StockReservation::query()->where('product_id', $product->id)->where('status', 'active')->value('quantity'));
    }

    public function test_Route_work_day_close_replays_same_idempotency_key_without_resubmitting(): void
    {
        [$business, $seller, $branch] = $this->tenant('pre_seller', ['routes'], reservePreSaleStock: true);
        $zone = RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => 'Ruta cierre idem',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente cierre']);
        $assignment = RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);
        $workDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'status' => 'open',
            'work_date' => now()->toDateString(),
            'started_at' => now(),
        ]);
        $visit = RouteVisit::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_zone_id' => $zone->id,
            'route_zone_customer_id' => $assignment->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'visit_order' => 1,
            'status' => 'with_pre_sale',
        ]);
        PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_visit_id' => $visit->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => 'draft',
            'subtotal' => 20,
            'discount_total' => 0,
            'total' => 20,
        ]);
        $payload = ['idempotency_key' => 'idem-route-close-1'];

        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $workDay), $payload)->assertRedirect(route('routes.mobile.zones'));
        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $workDay), $payload)->assertRedirect(route('routes.mobile.zones'));
        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $workDay), [
            'idempotency_key' => 'idem-route-close-distinct-key',
        ])->assertSessionHasErrors('work_day');

        $this->assertSame('closed', $workDay->refresh()->status);
        $this->assertSame('submitted', PreSale::query()->where('route_work_day_id', $workDay->id)->value('status'));
    }

    public function test_cash_register_close_blocks_a_second_request_after_the_session_is_closed(): void
    {
        [$business, $user, $branch] = $this->tenant('owner', ['cash_register']);
        $session = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->actingAs($user)->post(route('cash-register.close'), [
            'counted_cash' => 0,
        ])->assertRedirect(route('cash-register.closings.show', $session));

        $this->actingAs($user)->post(route('cash-register.close'), [
            'counted_cash' => 0,
        ])->assertSessionHasErrors('cash_register');

        $this->assertSame('closed', $session->refresh()->status);
        $this->assertNotNull($session->closed_at);
    }

    private function tenant(
        string $role,
        array $modules,
        bool $useBranches = false,
        bool $enableCredits = false,
        bool $enableCreditReservations = false,
        bool $reservePreSaleStock = false,
    ): array {
        $business = Business::query()->create([
            'name' => 'Tenant idem',
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => $useBranches,
            'allow_receipts' => true,
            'allow_invoices' => false,
            'enable_credit_sales' => $enableCredits,
            'enable_credit_reservations' => $enableCreditReservations,
            'reserve_pre_sale_stock' => $reservePreSaleStock,
        ]);

        foreach ($modules as $module) {
            TenantModule::query()->create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }

        $branch = BranchInventory::defaultBranch($business->id);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'current_branch_id' => $branch->id,
        ]);
        Permissions::assignRole($user, $role);

        return [$business, $user, $branch];
    }

    private function product(Business $business, Branch $branch, float $stock, float $salePrice): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto idem '.uniqid(),
            'sku' => 'IDEM-'.uniqid(),
            'sale_price' => $salePrice,
            'cost_price' => 10,
            'is_active' => true,
        ]);

        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
        ]);

        return $product;
    }

    private function creditReservation(
        Business $business,
        Branch $branch,
        Product $product,
        int $quantity,
        ?Customer $customer = null,
    ): CreditReceiptLine {
        $customer ??= Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente reserva',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'country' => 'GT',
        ]);

        $receipt = CreditReceipt::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_type' => $customer->doc_type,
            'customer_doc_number' => $customer->doc_number,
            'receipt_number' => 1,
            'status' => 'pending',
            'subtotal' => $quantity * (float) $product->sale_price,
            'discount_amount' => 0,
            'total' => $quantity * (float) $product->sale_price,
            'pending_total' => $quantity * (float) $product->sale_price,
        ]);

        return CreditReceiptLine::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'credit_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->code,
            'quantity' => $quantity,
            'qty_reserved' => $quantity,
            'qty_pending' => $quantity,
            'unit_price' => $product->sale_price,
            'line_total' => $quantity * (float) $product->sale_price,
            'pending_total' => $quantity * (float) $product->sale_price,
            'status' => 'pending',
        ]);
    }
}
