<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\ElectronicDocument;
use App\Models\FelReconciliationRequest;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Routes\RoutePreSaleFelEligibilityService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class RoutePreSaleInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_picked_pre_sale_converts_to_paid_receipt_using_picked_quantities_and_preserved_prices(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 4, pickedQuantity: 2, discount: 20);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash'))
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();
        $line = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame('receipt', $sale->document_type);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(100.0, (float) $line->unit_price);
        $this->assertSame(190.0, (float) $sale->total);
        $this->assertSame(190.0, (float) $line->total);
        $this->assertSame(8.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('stock_movements', ['business_id' => $business->id, 'product_id' => $product->id, 'type' => 'sale', 'quantity' => -2]);
        $this->assertDatabaseHas('cash_movements', ['business_id' => $business->id, 'reference_type' => 'sale', 'reference_id' => $sale->id, 'amount' => 190]);
        $this->assertSame(0, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'consumed')->count());
        $this->assertSame(PreSale::STATUS_CONVERTED, $preSale->refresh()->status);
        $this->assertSame($sale->id, $preSale->converted_sale_id);
        $this->assertNotNull($preSale->converted_at);
        $this->assertNotNull($preSale->workDay->refresh()->completed_at);
    }

    public function test_route_documentary_closure_always_creates_a_receipt_without_starting_fel(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('invoice', 'paid', 'cash'))
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();

        $this->assertSame('receipt', $sale->document_type);
        $this->assertSame($sale->id, $preSale->refresh()->converted_sale_id);
        $this->assertDatabaseMissing('electronic_documents', ['sale_id' => $sale->id]);
    }

    public function test_internal_route_receipt_can_be_certified_manually_without_creating_another_sale_or_stock_movement(): void
    {
        [$business, $admin, $branch] = $this->tenant(allowInvoices: true);
        $this->felSettings($business, $branch);
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash'))
            ->assertSessionHasNoErrors();

        $sale = $preSale->refresh()->convertedSale()->firstOrFail();
        $stockBeforeCertification = (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock');

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $certifiedSale): ElectronicDocument {
            $document = $certifiedSale->electronicDocument()->firstOrFail();
            $document->update(['status' => 'certified', 'uuid' => 'route-receipt-fel-uuid', 'series' => 'A', 'number' => '1', 'certification_date' => now()]);
            $certifiedSale->update(['certification_status' => 'certified', 'fel_status' => 'CERTIFIED', 'fel_uuid' => 'route-receipt-fel-uuid']);

            return $document->refresh();
        });
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->actingAs($admin)
            ->post("/routes/pre-sales/{$preSale->id}/fel/certify", ['idempotency_key' => 'route-receipt-first-fel-key'])
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame('receipt', $sale->refresh()->document_type);
        $this->assertDatabaseHas('electronic_documents', ['sale_id' => $sale->id, 'status' => 'certified']);
        $this->assertSame($stockBeforeCertification, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('product_id', $product->id)->count());
    }

    public function test_failed_route_fel_keeps_the_internal_receipt_and_all_existing_operational_effects(): void
    {
        [$business, $admin, $branch] = $this->tenant(allowInvoices: true);
        $this->felSettings($business, $branch);
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash'))
            ->assertSessionHasNoErrors();

        $sale = $preSale->refresh()->convertedSale()->firstOrFail();
        $stockBeforeFel = (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock');
        $cashMovementsBeforeFel = \App\Models\CashMovement::query()->where('business_id', $business->id)->count();

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $failedSale): never {
            $failedSale->electronicDocument()->firstOrFail()->update(['status' => 'failed', 'error_message' => 'Digifact rechazó la solicitud.']);
            $failedSale->update(['certification_status' => 'failed']);

            throw new FelException('Digifact rechazó la solicitud.');
        });
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->post(route('routes.pre-sales.fel.certify', $preSale), ['idempotency_key' => 'route-pre-sale-failed-fel-key'])
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors('fel');

        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame('receipt', $sale->refresh()->document_type);
        $this->assertSame(PreSale::STATUS_CONVERTED, $preSale->refresh()->status);
        $this->assertSame($sale->id, $preSale->converted_sale_id);
        $this->assertSame($stockBeforeFel, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('product_id', $product->id)->count());
        $this->assertSame($cashMovementsBeforeFel, \App\Models\CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('electronic_documents', ['sale_id' => $sale->id, 'status' => 'failed']);
    }

    public function test_unknown_route_fel_requires_reconciliation_before_another_attempt(): void
    {
        [$business, $admin, $branch] = $this->tenant(allowInvoices: true);
        $this->felSettings($business, $branch);
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())->assertSessionHasNoErrors();
        $sale = $preSale->refresh()->convertedSale()->firstOrFail();

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $unknownSale): never {
            $unknownSale->electronicDocument()->firstOrFail()->update(['status' => 'unknown', 'error_message' => 'Tiempo de espera agotado.']);
            $unknownSale->update(['certification_status' => 'unknown']);

            throw new FelException('Tiempo de espera agotado.');
        });
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.fel.certify', $preSale), ['idempotency_key' => 'route-pre-sale-unknown-fel-key'])
            ->assertSessionHasErrors('fel');

        $this->assertDatabaseHas('fel_reconciliation_requests', ['business_id' => $business->id, 'sale_id' => $sale->id, 'status' => 'pending']);
        $this->actingAs($admin)
            ->post(route('routes.pre-sales.fel.certify', $preSale), ['idempotency_key' => 'route-pre-sale-unknown-fel-retry-key'])
            ->assertSessionHasErrors('fel');
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
    }

    public function test_route_fel_certification_is_scoped_to_the_pre_sale_tenant_and_active_branch(): void
    {
        [$business, $admin, $branch] = $this->tenant(allowInvoices: true);
        $this->felSettings($business, $branch);
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $this->openCashRegister($business, $branch, $admin);
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())->assertSessionHasNoErrors();

        [$otherBusiness, $otherAdmin] = $this->tenant(allowInvoices: true);
        $this->actingAs($otherAdmin)
            ->post(route('routes.pre-sales.fel.certify', $preSale), ['idempotency_key' => 'route-pre-sale-other-tenant-key'])
            ->assertForbidden();

        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Otra sucursal '.uniqid(),
            'code' => 'OTHER-'.uniqid(),
            'is_active' => true,
        ]);
        $admin->update(['current_branch_id' => $otherBranch->id]);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.fel.certify', $preSale), ['idempotency_key' => 'route-pre-sale-other-branch-key'])
            ->assertForbidden();
    }

    public function test_route_pre_sales_queue_exposes_and_filters_fel_status_without_cross_tenant_matches(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $this->openCashRegister($business, $branch, $admin);
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())->assertSessionHasNoErrors();

        [$otherBusiness, $otherAdmin, $otherBranch] = $this->tenant();
        $otherProduct = $this->product($otherBusiness, $otherBranch);
        $otherPreSale = $this->pickedPreSale($otherBusiness, $otherBranch, $otherAdmin, $otherProduct);
        $this->openCashRegister($otherBusiness, $otherBranch, $otherAdmin);
        $this->actingAs($otherAdmin)->post(route('routes.pre-sales.invoice', $otherPreSale), $this->invoicePayload())->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.index', ['status' => 'converted', 'fel_status' => 'not_requested']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Index')
                ->where('preSales.data.0.id', $preSale->id)
                ->where('preSales.data.0.fel_status', 'not_requested')
            ->where('preSales.total', 1));
    }

    public function testRoutePreSaleFelEligibilityAppliesCfThresholdAndVerifiedNitRules(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 24, pickedQuantity: 24);
        $eligibility = app(RoutePreSaleFelEligibilityService::class);

        $preSale->customer->update(['doc_type' => 'CF', 'doc_number' => 'CF', 'is_final_consumer' => true]);
        $this->assertSame('eligible', $eligibility->evaluate($preSale->fresh('customer'))['status']);

        $preSale->update(['total' => 2500]);
        $atLimit = $eligibility->evaluate($preSale->fresh('customer'));
        $this->assertSame('not_eligible', $atLimit['status']);
        $this->assertSame('final_consumer_limit', $atLimit['reason_code']);
        $this->assertSame('Consumidor Final no puede certificarse por Q2,500.00 o más.', $atLimit['reason']);

        $preSale->customer->update([
            'doc_type' => 'NIT',
            'doc_number' => '',
            'is_final_consumer' => false,
            'tax_lookup_verified_at' => now(),
            'name_locked' => true,
        ]);
        $this->assertSame('invalid_tax_id', $eligibility->evaluate($preSale->fresh('customer'))['reason_code']);

        $preSale->customer->update([
            'doc_type' => 'NIT',
            'doc_number' => '1234-567',
            'is_final_consumer' => false,
            'tax_lookup_verified_at' => null,
            'name_locked' => false,
        ]);
        $this->assertSame('not_eligible', $eligibility->evaluate($preSale->fresh('customer'))['status']);

        $preSale->customer->update(['tax_lookup_verified_at' => now(), 'name_locked' => true]);
        $this->assertSame('eligible', $eligibility->evaluate($preSale->fresh('customer'))['status']);
    }

    public function test_ineligible_customer_does_not_block_internal_receipt_when_tenant_policy_is_disabled(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $preSale->customer->update(['doc_type' => 'NIT', 'doc_number' => '1234567', 'tax_lookup_verified_at' => null, 'name_locked' => false]);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(PreSale::STATUS_CONVERTED, $preSale->refresh()->status);
        $this->assertSame('not_eligible', $preSale->fel_eligibility_status);
        $this->assertSame('nit_not_verified', $preSale->fel_eligibility_reason_code);
    }

    public function test_ineligible_customer_blocks_internal_receipt_when_tenant_requires_fel_eligibility(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_require_fel_eligible_customer' => true]);
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $preSale->customer->update(['doc_type' => 'NIT', 'doc_number' => '1234567', 'tax_lookup_verified_at' => null, 'name_locked' => false]);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors('pre_sale');

        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
        $this->assertNull($preSale->converted_sale_id);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_ineligible_customer_cannot_start_manual_fel_or_create_an_electronic_document(): void
    {
        [$business, $admin, $branch] = $this->tenant(allowInvoices: true);
        $this->felSettings($business, $branch);
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $preSale->customer->update(['doc_type' => 'NIT', 'doc_number' => '1234567', 'tax_lookup_verified_at' => null, 'name_locked' => false]);
        $this->openCashRegister($business, $branch, $admin);
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.fel.certify', $preSale), ['idempotency_key' => 'ineligible-route-fel-key'])
            ->assertSessionHasErrors('fel');

        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertSame('not_eligible', $preSale->refresh()->fel_eligibility_status);
    }

    public function test_route_pre_sales_queue_filters_persisted_fel_ineligibility_within_the_current_tenant(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $preSale->customer->update(['doc_type' => 'NIT', 'doc_number' => '1234567', 'tax_lookup_verified_at' => null, 'name_locked' => false]);
        $this->openCashRegister($business, $branch, $admin);
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())->assertSessionHasNoErrors();

        [$otherBusiness, $otherAdmin, $otherBranch] = $this->tenant();
        $otherProduct = $this->product($otherBusiness, $otherBranch);
        $otherPreSale = $this->pickedPreSale($otherBusiness, $otherBranch, $otherAdmin, $otherProduct);
        $otherPreSale->customer->update(['doc_type' => 'NIT', 'doc_number' => '1234567', 'tax_lookup_verified_at' => null, 'name_locked' => false]);
        $this->openCashRegister($otherBusiness, $otherBranch, $otherAdmin);
        $this->actingAs($otherAdmin)->post(route('routes.pre-sales.invoice', $otherPreSale), $this->invoicePayload())->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.index', ['status' => 'converted', 'fel_eligibility' => 'not_eligible']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Index')
                ->where('preSales.data.0.id', $preSale->id)
                ->where('preSales.data.0.fel_eligibility.status', 'not_eligible')
                ->where('preSales.total', 1));
    }

    public function test_picking_timing_conversion_creates_sale_without_a_second_stock_deduction_or_reservation_consumption(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_stock_deduction_timing' => 'picking']);
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);
        $item = $preSale->items()->firstOrFail();
        $item->update(['stock_deducted_quantity' => 2]);
        [$previousStock, $newStock] = BranchInventory::decrease($product, $branch->id, 2);
        StockMovement::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'type' => 'pre_sale_picking',
            'quantity' => -2,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'note' => 'Preparación de prueba',
            'created_by' => $admin->id,
        ]);
        StockReservation::query()->where('source_id', $preSale->id)->update(['status' => 'consumed', 'consumed_at' => now()]);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash'))
            ->assertSessionHasNoErrors();

        $this->assertSame(8.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(1, StockMovement::query()->where('business_id', $business->id)->where('product_id', $product->id)->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->where('type', 'sale')->count());
        $this->assertSame(PreSale::STATUS_CONVERTED, $preSale->refresh()->status);
        $this->assertSame(1, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'consumed')->count());
    }

    public function test_credit_conversion_creates_receivable_without_cash_movement(): void
    {
        [$business, $admin, $branch] = $this->tenant(enableCreditSales: true);
        $product = $this->product($business, $branch, stock: 10, price: 75);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'credit'))
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertTrue($sale->is_credit_sale);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame(150.0, (float) $sale->credit_balance);
        $this->assertDatabaseHas('customer_account_movements', ['business_id' => $business->id, 'sale_id' => $sale->id, 'type' => 'charge', 'amount' => 150]);
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->where('type', 'payment')->count());
        $this->assertSame(0, Sale::query()->find($sale->id)->payments()->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_same_idempotency_key_replays_and_a_different_payload_conflicts_without_second_sale(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $this->openCashRegister($business, $branch, $admin);
        $payload = $this->invoicePayload('receipt', 'paid', 'cash', 'pre-sale-invoice-replay-key');

        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $payload)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), [...$payload, 'payment_method' => 'card'])
            ->assertStatus(409);

        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
    }

    public function test_only_picked_pre_sales_can_be_invoiced_and_invoice_permission_is_required(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch);
        $picked = $this->pickedPreSale($business, $branch, $admin, $product);
        $submitted = $this->pickedPreSale($business, $branch, $admin, $product);
        $submitted->update(['status' => PreSale::STATUS_SUBMITTED, 'picked_at' => null, 'picked_by' => null]);

        $admin->roles()->detach();
        Permissions::assignDirectPermissions($admin, [Permissions::ROUTES_PRE_SALES_ADMIN_VIEW]);
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $picked), $this->invoicePayload())->assertForbidden();

        Permissions::assignDirectPermissions($admin, [Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_PRE_SALES_INVOICE]);
        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $submitted), $this->invoicePayload())
            ->assertSessionHasErrors('pre_sale');
    }

    public function test_internal_receipt_remains_available_when_receipts_are_disabled_for_pos(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        TenantSetting::query()->where('business_id', $business->id)->update(['allow_receipts' => false]);
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.show', $preSale))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('canInvoice', true)
                ->where('invoiceOptions.document_types', ['receipt']));
    }

    public function test_invoice_ui_hides_credit_when_the_user_cannot_create_credit_sales(): void
    {
        [$business, $admin, $branch] = $this->tenant(enableCreditSales: true);
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);

        $admin->roles()->detach();
        Permissions::assignDirectPermissions($admin, [
            Permissions::ROUTES_PRE_SALES_ADMIN_VIEW,
            Permissions::ROUTES_PRE_SALES_INVOICE,
        ]);

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.show', $preSale))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('canInvoice', true)
                ->where('invoiceOptions.credit_enabled', false));
    }

    public function test_credit_conversion_is_rejected_when_credit_sales_are_disabled(): void
    {
        [$business, $admin, $branch] = $this->tenant(enableCreditSales: false);
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.show', $preSale))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoiceOptions.credit_enabled', false));

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'credit'))
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors('payment_condition');

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
    }

    public function test_invoice_ui_hides_credit_when_the_credits_module_is_disabled(): void
    {
        [$business, $admin, $branch] = $this->tenant(enableCreditSales: true);
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        TenantModule::query()
            ->where('business_id', $business->id)
            ->where('module', 'credits')
            ->update(['is_enabled' => false]);

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.show', $preSale))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoiceOptions.credit_enabled', false));
    }

    public function test_successful_fel_conversion_marks_the_document_certified_and_consumes_the_reservation(): void
    {
        [$business, $admin, $branch] = $this->tenant(allowInvoices: true);
        $this->felSettings($business, $branch);
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);
        $this->openCashRegister($business, $branch, $admin);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $sale): ElectronicDocument {
            $document = $sale->electronicDocument()->firstOrFail();
            $document->update([
                'status' => 'certified',
                'uuid' => 'route-pre-sale-fel-uuid',
                'series' => 'A',
                'number' => '1',
                'certification_date' => now(),
            ]);
            $sale->update([
                'certification_status' => 'certified',
                'fel_status' => 'CERTIFIED',
                'fel_uuid' => 'route-pre-sale-fel-uuid',
            ]);

            return $document->refresh();
        });
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash'))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.fel.certify', $preSale), ['idempotency_key' => 'route-pre-sale-successful-fel-key'])
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('certified', $sale->certification_status);
        $this->assertDatabaseHas('electronic_documents', ['sale_id' => $sale->id, 'status' => 'certified']);
        $this->assertSame(8.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->count());
        $this->assertSame(PreSale::STATUS_CONVERTED, $preSale->refresh()->status);
    }

    public function test_a_different_idempotency_key_cannot_convert_an_already_converted_pre_sale(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash', 'route-pre-sale-first-key'))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash', 'route-pre-sale-second-key'))
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors('pre_sale');

        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
    }

    public function test_conversion_is_scoped_to_the_current_tenant_and_active_branch(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        [$otherBusiness, $otherAdmin, $otherBranch] = $this->tenant();
        $otherTenantProduct = $this->product($otherBusiness, $otherBranch);
        $otherTenantPreSale = $this->pickedPreSale($otherBusiness, $otherBranch, $otherAdmin, $otherTenantProduct);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $otherTenantPreSale), $this->invoicePayload())
            ->assertForbidden();

        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Otra sucursal '.uniqid(),
            'code' => 'OTHER-'.uniqid(),
            'is_active' => true,
        ]);
        $otherBranchProduct = $this->product($business, $otherBranch);
        $otherBranchPreSale = $this->pickedPreSale($business, $otherBranch, $admin, $otherBranchProduct);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $otherBranchPreSale), $this->invoicePayload())
            ->assertForbidden();
    }

    public function test_converted_pre_sale_cannot_return_to_picking_cancellation_or_processing(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload())
            ->assertSessionHasNoErrors();

        $item = $preSale->items()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'idempotency_key' => 'route-pre-sale-repick-key',
                'items' => [['id' => $item->id, 'picked_quantity' => 1]],
            ])
            ->assertSessionHasErrors('pre_sale');
        $this->actingAs($admin)
            ->post(route('routes.pre-sales.cancel', $preSale), ['idempotency_key' => 'route-pre-sale-cancel-key'])
            ->assertSessionHasErrors('pre_sale');
        $this->actingAs($admin)
            ->post(route('routes.pre-sales.processing', $preSale), ['idempotency_key' => 'route-pre-sale-process-key'])
            ->assertSessionHasErrors('pre_sale');

        $this->assertSame(PreSale::STATUS_CONVERTED, $preSale->refresh()->status);
        $source = file_get_contents(resource_path('js/Pages/Routes/PreSales/Show.tsx'));
        $this->assertStringContainsString("preSale.status === 'picked' && canInvoice && !preSale.converted_sale", $source);
        $this->assertStringContainsString("preSale.status === 'converted' && preSale.converted_sale", $source);
        $this->assertStringContainsString('Comprobante interno', $source);
    }

    private function tenant(bool $enableCreditSales = false, bool $allowInvoices = false): array
    {
        $business = Business::query()->create([
            'name' => 'Route invoice '.uniqid(),
            'slug' => 'route-invoice-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => $allowInvoices,
            'enable_credit_sales' => $enableCreditSales,
            'allow_negative_stock' => false,
            'route_pre_sale_invoicing_mode' => 'manual',
        ]);

        foreach (['routes', 'pos', 'inventory', 'branches', 'credits', 'cash_register', 'fel_gt'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }

        $branch = BranchInventory::defaultBranchForBusiness($business);
        $admin = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'current_branch_id' => $branch->id,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        Permissions::assignRole($admin, 'owner');

        return [$business, $admin, $branch];
    }

    private function product(Business $business, Branch $branch, float $stock = 10, float $price = 100): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto preventa '.uniqid(),
            'code' => 'RPI-'.uniqid(),
            'cost_price' => 40,
            'sale_price' => $price,
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]);
        $priceType = PriceType::query()->create(['business_id' => $business->id, 'name' => 'General', 'is_default' => true, 'is_active' => true]);
        ProductPrice::query()->create(['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $priceType->id, 'price' => $price, 'is_active' => true]);

        return $product;
    }

    private function pickedPreSale(Business $business, Branch $branch, User $seller, Product $product, int $quantity = 1, int $pickedQuantity = 1, float $discount = 0): PreSale
    {
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente ruta '.uniqid(),
            'doc_type' => 'NIT',
            'doc_number' => (string) random_int(1000000, 9999999),
            'address' => 'Huehuetenango',
            'country' => 'GT',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $seller->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'work_date' => today(),
            'status' => 'closed',
            'started_at' => now()->subHour(),
            'closed_at' => now(),
        ]);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => PreSale::STATUS_PICKED,
            'subtotal' => $quantity * $product->sale_price,
            'discount_total' => $discount,
            'total' => ($quantity * $product->sale_price) - $discount,
            'picked_at' => now(),
            'picked_by' => $seller->id,
        ]);
        $item = PreSaleItem::query()->create([
            'business_id' => $business->id,
            'pre_sale_id' => $preSale->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'picked_quantity' => $pickedQuantity,
            'unit_price' => $product->sale_price,
            'original_price' => $product->sale_price,
            'manual_price' => false,
            'discount' => $discount,
            'total' => ($quantity * $product->sale_price) - $discount,
        ]);
        StockReservation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'source_type' => 'pre_sale',
            'source_id' => $preSale->id,
            'source_item_id' => $item->id,
            'quantity' => $pickedQuantity,
            'status' => 'active',
            'created_by' => $seller->id,
        ]);

        return $preSale->load('workDay');
    }

    private function openCashRegister(Business $business, Branch $branch, User $user): void
    {
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    private function felSettings(Business $business, Branch $branch): void
    {
        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'secret',
            'test_base_url' => 'https://testnucgt.digifact.com/api',
            'establishment_code' => '1',
            'establishment_name' => 'Casa Matriz',
            'establishment_address' => 'Ciudad',
            'establishment_postal_code' => '01001',
            'establishment_municipality' => 'Guatemala',
            'establishment_department' => 'Guatemala',
            'establishment_country' => 'GT',
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

    private function invoicePayload(string $documentType = 'receipt', string $paymentCondition = 'paid', ?string $paymentMethod = 'cash', ?string $key = null): array
    {
        return [
            'idempotency_key' => $key ?: 'route-pre-sale-invoice-'.str_replace('.', '-', uniqid('', true)),
            'document_type' => $documentType,
            'payment_condition' => $paymentCondition,
            'payment_method' => $paymentMethod,
            'note' => 'Facturación de ruta',
        ];
    }
}
