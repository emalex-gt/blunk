<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CashRegisterSession;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteWorkDay;
use App\Models\RoutePreparationBatch;
use App\Models\RouteZone;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Routes\RouteGlobalOperationsService;
use App\Services\Routes\RouteGlobalPreparationDocuments;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Models\StockReservation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RouteGlobalOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_preparation_preview_groups_eligible_pre_sales_by_seller_and_reports_blocked_rows(): void
    {
        $business = Business::query()->create(['name' => 'Global '.uniqid(), 'slug' => 'global-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $firstSeller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $secondSeller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $firstDay = $this->workDay($business->id, $branch->id, $firstSeller->id);
        $secondDay = $this->workDay($business->id, $branch->id, $secondSeller->id);
        $this->preSale($business->id, $branch->id, $firstDay->id, $firstSeller->id, '100.00', PreSale::STATUS_SUBMITTED);
        $this->preSale($business->id, $branch->id, $secondDay->id, $secondSeller->id, '75.50', PreSale::STATUS_PROCESSING);
        $this->preSale($business->id, $branch->id, $secondDay->id, $secondSeller->id, '50.00', PreSale::STATUS_CANCELLED);

        $preview = app(RouteGlobalOperationsService::class)->preparationPreview($business->id, $branch->id);

        $this->assertSame(3, $preview['summary']['pre_sales']);
        $this->assertSame(2, $preview['summary']['sellers']);
        $this->assertSame(225.5, $preview['summary']['total']);
        $this->assertSame(0, $preview['summary']['prepared_count']);
        $this->assertSame(0, $preview['summary']['converted_count']);
        $this->assertSame(2, $preview['summary']['preparation_eligible_count']);
        $this->assertSame(0, $preview['summary']['sales_eligible_count']);
        $this->assertCount(2, $preview['sellers']);
        $this->assertCount(1, $preview['blocked']);
        $this->assertSame('pre_sale_status_ineligible', $preview['blocked'][0]['reason_code']);
    }

    public function test_global_previews_expose_unique_commercial_metrics_per_seller_across_work_days(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        TenantSetting::query()->where('business_id', $business->id)->update([
            'route_collection_responsibility' => 'delivery_agent',
            'route_delivery_tracking' => 'external',
            'route_pre_sale_invoicing_mode' => 'manual',
        ]);

        $converted = $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-metrics-prepare-0001');
        app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-metrics-sales-0001');
        $this->assertNotNull($converted->refresh()->converted_sale_id);

        $this->preSale($business->id, $branch->id, $workDays[0]->id, $sellerA->id, '10.00', PreSale::STATUS_SUBMITTED);
        $this->preSale($business->id, $branch->id, $workDays[1]->id, $sellerA->id, '20.00', PreSale::STATUS_PROCESSING);
        $this->preSale($business->id, $branch->id, $workDays[1]->id, $sellerA->id, '40.00', PreSale::STATUS_PICKED);
        $this->preSale($business->id, $branch->id, $workDays[2]->id, $sellerB->id, '30.00', PreSale::STATUS_SUBMITTED);
        $this->preSale($business->id, $branch->id, $workDays[2]->id, $sellerB->id, '50.00', PreSale::STATUS_PICKED);

        $preparation = app(RouteGlobalOperationsService::class)->preparationPreview($business->id, $branch->id);
        $sales = app(RouteGlobalOperationsService::class)->salesPreview($business->id, $branch->id);
        $sellers = collect($preparation['sellers'])->keyBy('seller.id');

        $this->assertSame(6, $preparation['summary']['pre_sales']);
        $this->assertSame(210.0, $preparation['summary']['total']);
        $this->assertSame(3, $preparation['summary']['prepared_count']);
        $this->assertSame(1, $preparation['summary']['converted_count']);
        $this->assertSame(3, $preparation['summary']['preparation_eligible_count']);
        $this->assertSame(2, $preparation['summary']['sales_eligible_count']);
        $this->assertSame(3, $sales['summary']['prepared_count']);
        $this->assertSame(1, $sales['summary']['converted_count']);
        $this->assertSame(2, $sales['summary']['sales_eligible_count']);
        $this->assertSame(4, $sellers[$sellerA->id]['total_pre_sales']);
        $this->assertSame(130.0, $sellers[$sellerA->id]['total_amount']);
        $this->assertSame(2, $sellers[$sellerA->id]['prepared_count']);
        $this->assertSame(1, $sellers[$sellerA->id]['converted_count']);
        $this->assertSame(2, $sellers[$sellerA->id]['preparation_eligible_count']);
        $this->assertSame(1, $sellers[$sellerA->id]['sales_eligible_count']);
        $this->assertSame(2, $sellers[$sellerB->id]['total_pre_sales']);
        $this->assertSame(80.0, $sellers[$sellerB->id]['total_amount']);
        $this->assertSame(1, $sellers[$sellerB->id]['prepared_count']);
        $this->assertSame(0, $sellers[$sellerB->id]['converted_count']);
    }

    public function test_global_operations_markup_uses_operation_specific_eligibility_for_disabled_actions(): void
    {
        $markup = file_get_contents(resource_path('js/Pages/Routes/GlobalOperations/Index.tsx'));

        $this->assertStringContainsString("const preparationEligible = preparation_preview.summary.preparation_eligible_count;", $markup);
        $this->assertStringContainsString("const salesEligible = sales_preview.summary.sales_eligible_count;", $markup);
        $this->assertStringContainsString('disabled={preparationEligible === 0}', $markup);
        $this->assertStringContainsString('disabled={salesEligible === 0}', $markup);
        $this->assertStringContainsString('value={`${preparation_preview.summary.prepared_count}/${preparation_preview.summary.pre_sales}`}', $markup);
        $this->assertStringContainsString('value={`${preparation_preview.summary.converted_count}/${preparation_preview.summary.pre_sales}`}', $markup);
        $this->assertStringContainsString('preparationEligible === 1 ? \'1 pedido pendiente de preparar.\'', $markup);
        $this->assertStringContainsString('`${preparationEligible} pedidos pendientes de preparar.`', $markup);
        $this->assertStringContainsString('No hay pedidos pendientes de preparar.', $markup);
        $this->assertStringContainsString('No hay pedidos preparados pendientes de generar ventas.', $markup);
    }

    public function test_sales_preview_blocks_the_entire_work_day_with_policy_aware_reasons_before_global_execution(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        $valid = $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $invalid = $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '40.00');
        app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-policy-preview-prepare');
        $invalid->refresh()->update(['agreed_payment_method' => null]);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);

        $preview = app(RouteGlobalOperationsService::class)->salesPreview($business->id, $branch->id);
        $execution = app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-policy-preview-sales');

        $this->assertSame(0, $preview['summary']['sales_eligible_count']);
        $this->assertSame($workDays[0]->id, $preview['blocked'][0]['work_day_id']);
        $this->assertSame($invalid->id, $preview['blocked'][0]['pre_sale_id']);
        $this->assertSame('missing_agreed_payment_method', $preview['blocked'][0]['reason_code']);
        $this->assertSame([], $execution['processed']);
        $this->assertDatabaseCount('route_delivery_batches', 0);
        $this->assertNull($valid->fresh()->converted_sale_id);
        $this->assertNull($invalid->fresh()->converted_sale_id);
    }

    public function test_sales_preview_allows_immediate_paid_when_an_open_cash_session_exists(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        $preSale = $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-immediate-preview-prepare');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);

        $preview = app(RouteGlobalOperationsService::class)->salesPreview($business->id, $branch->id);
        $execution = app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-immediate-preview-sales');

        $this->assertSame(1, $preview['summary']['sales_eligible_count']);
        $this->assertSame([], $preview['blocked']);
        $this->assertCount(1, $execution['processed']);
        $this->assertSame('paid', $preSale->fresh()->convertedSale->payment_status);
    }

    public function test_sales_preview_requires_a_cash_session_for_immediate_paid(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'delivery_agent']);
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-immediate-preview-no-cash-prepare');
        CashRegisterSession::query()->where('business_id', $business->id)->update(['status' => 'closed', 'closed_at' => now()]);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'cash',
        ]);

        $preview = app(RouteGlobalOperationsService::class)->salesPreview($business->id, $branch->id);

        $this->assertSame(0, $preview['summary']['sales_eligible_count']);
        $this->assertSame('cash_session_required', $preview['blocked'][0]['reason_code']);
        $this->assertNull($preview['blocked'][0]['pre_sale_id']);
    }

    public function test_prepare_all_executes_real_child_batches_per_eligible_work_day_and_replays_without_duplicate_stock(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $this->preparablePreSale($business, $branch, $workDays[1], $sellerA, '40.00');
        $this->preparablePreSale($business, $branch, $workDays[2], $sellerB, '20.00');
        $this->preSale($business->id, $branch->id, $workDays[2]->id, $sellerB->id, '15.00', PreSale::STATUS_CANCELLED);

        $first = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-prepare-replay-0001');
        $movements = \App\Models\StockMovement::query()->where('business_id', $business->id)->count();
        $second = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-prepare-replay-0001');
        $newKeyRetry = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-prepare-new-key-0001');

        $this->assertCount(3, $first['processed']);
        $this->assertCount(1, $first['blocked']);
        $this->assertSame([], $first['failed']);
        $this->assertCount(3, array_unique(array_column($first['processed'], 'batch_id')));
        $this->assertSame(3, \App\Models\RoutePreparationBatch::query()->where('business_id', $business->id)->count());
        $this->assertSame($movements, \App\Models\StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(array_column($first['processed'], 'batch_id'), array_column($second['processed'], 'batch_id'));
        $this->assertSame([], $newKeyRetry['processed']);
        $this->assertSame([], $newKeyRetry['failed']);
        $this->assertSame(3, \App\Models\RoutePreparationBatch::query()->where('business_id', $business->id)->count());
    }

    public function test_prepare_all_classifies_known_child_validation_as_blocked_and_continues_other_work_days(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $this->preSale($business->id, $branch->id, $workDays[1]->id, $sellerA->id, '40.00', PreSale::STATUS_SUBMITTED);
        $this->preparablePreSale($business, $branch, $workDays[2], $sellerB, '20.00');

        $result = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-child-validation-0001');

        $this->assertCount(2, $result['processed']);
        $this->assertCount(1, $result['blocked']);
        $this->assertSame([], $result['failed']);
        $this->assertSame($workDays[1]->id, $result['blocked'][0]['work_day_id']);
        $this->assertSame(2, RoutePreparationBatch::query()->where('business_id', $business->id)->count());
    }

    public function test_global_documents_group_only_the_explicit_child_batches_by_seller(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $this->preparablePreSale($business, $branch, $workDays[1], $sellerA, '40.00');
        $this->preparablePreSale($business, $branch, $workDays[2], $sellerB, '20.00');
        $execution = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-documents-0001');
        $batchIds = array_column($execution['processed'], 'batch_id');

        $document = app(RouteGlobalPreparationDocuments::class)->forBatches($business->id, $branch->id, $batchIds);

        $this->assertCount(2, $document['sellers']);
        $this->assertSame($sellerA->id, $document['sellers'][0]['seller']['id']);
        $this->assertCount(2, $document['sellers'][0]['orders']);
        $this->assertSame($sellerB->id, $document['sellers'][1]['seller']['id']);
        $this->assertSame($batchIds, $document['batch_ids']);
    }

    public function test_global_documents_reject_the_entire_requested_batch_set_when_any_id_is_missing_or_out_of_scope(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $execution = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-document-scope-0001');
        $validId = $execution['processed'][0]['batch_id'];

        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Otra sucursal',
            'code' => 'OTRA-'.uniqid(),
            'is_active' => true,
        ]);
        $otherBranchDay = $this->workDay($business->id, $otherBranch->id, $sellerA->id);
        $otherBranchBatch = RoutePreparationBatch::query()->create([
            'business_id' => $business->id,
            'branch_id' => $otherBranch->id,
            'route_work_day_id' => $otherBranchDay->id,
            'route_zone_id' => $otherBranchDay->route_zone_id,
            'prepared_by' => $actor->id,
            'prepared_at' => now(),
            'status' => RoutePreparationBatch::STATUS_COMPLETED,
            'stock_deduction_timing' => 'picking',
            'invoicing_mode' => 'manual',
            'total_pre_sales' => 0,
            'total_items' => 0,
            'total_amount' => 0,
        ]);

        $service = app(RouteGlobalPreparationDocuments::class);
        foreach ([[999999], [$validId, 999999], [$validId, $otherBranchBatch->id]] as $ids) {
            try {
                $service->forBatches($business->id, $branch->id, $ids);
                $this->fail('Los IDs de lote inválidos deben rechazar toda la operación documental.');
            } catch (ValidationException) {
                $this->assertSame(1, RoutePreparationBatch::query()->whereKey($validId)->count());
            }
        }
    }

    public function test_global_documents_reject_foreign_business_and_non_completed_batches(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $validId = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-document-status-0001')['processed'][0]['batch_id'];

        $foreignBusiness = Business::query()->create(['name' => 'Ajeno '.uniqid(), 'slug' => 'ajeno-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $foreignBranch = BranchInventory::defaultBranchForBusiness($foreignBusiness);
        $foreignSeller = User::factory()->create(['business_id' => $foreignBusiness->id, 'current_branch_id' => $foreignBranch->id, 'is_active' => true]);
        $foreignDay = $this->workDay($foreignBusiness->id, $foreignBranch->id, $foreignSeller->id);
        $foreignBatch = RoutePreparationBatch::query()->create([
            'business_id' => $foreignBusiness->id, 'branch_id' => $foreignBranch->id, 'route_work_day_id' => $foreignDay->id,
            'route_zone_id' => $foreignDay->route_zone_id, 'prepared_by' => $foreignSeller->id, 'prepared_at' => now(),
            'status' => RoutePreparationBatch::STATUS_COMPLETED, 'stock_deduction_timing' => 'picking', 'invoicing_mode' => 'manual',
            'total_pre_sales' => 0, 'total_items' => 0, 'total_amount' => 0,
        ]);
        $processingBatch = RoutePreparationBatch::query()->create([
            'business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDays[0]->id,
            'route_zone_id' => $workDays[0]->route_zone_id, 'prepared_by' => $actor->id, 'prepared_at' => now(),
            'status' => RoutePreparationBatch::STATUS_PROCESSING, 'stock_deduction_timing' => 'picking', 'invoicing_mode' => 'manual',
            'total_pre_sales' => 0, 'total_items' => 0, 'total_amount' => 0,
        ]);

        $service = app(RouteGlobalPreparationDocuments::class);
        foreach ([[$validId, $foreignBatch->id], [$validId, $processingBatch->id]] as $ids) {
            try {
                $service->forBatches($business->id, $branch->id, $ids);
                $this->fail('Los lotes ajenos o no completados no pueden alimentar documentos globales.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_global_documents_group_sellers_by_identity_even_when_their_display_names_match(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $sellerA->update(['name' => 'Vendedor repetido']);
        $sellerB->update(['name' => 'Vendedor repetido']);
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $this->preparablePreSale($business, $branch, $workDays[2], $sellerB, '20.00');

        $execution = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-document-seller-id-0001');
        $document = app(RouteGlobalPreparationDocuments::class)->forBatches($business->id, $branch->id, array_column($execution['processed'], 'batch_id'));

        $this->assertCount(2, $document['sellers']);
        $this->assertSame('Vendedor repetido', $document['sellers'][0]['seller']['name']);
        $this->assertSame('Vendedor repetido', $document['sellers'][1]['seller']['name']);
        $this->assertNotSame($document['sellers'][0]['seller']['id'], $document['sellers'][1]['seller']['id']);
    }

    public function test_global_document_templates_render_seller_grouped_consolidated_products_and_half_letter_receipts(): void
    {
        $document = [
            'sellers' => [[
                'seller' => ['id' => 11, 'name' => 'Carlos'],
                'orders' => [[
                    'batch_id' => 101, 'work_day_id' => 41, 'pre_sale_id' => 501, 'customer' => (object) ['name' => 'Cliente A', 'address' => 'Zona 1'], 'total' => 100.00,
                    'items' => [(object) ['product' => (object) ['name' => 'Producto X', 'code' => 'X'], 'picked_quantity' => 5, 'unit_price' => 20, 'discount' => 0, 'quantity' => 5]],
                ], [
                    'batch_id' => 102, 'work_day_id' => 42, 'pre_sale_id' => 502, 'customer' => (object) ['name' => 'Cliente B', 'address' => 'Zona 2'], 'total' => 140.00,
                    'items' => [(object) ['product' => (object) ['name' => 'Producto X', 'code' => 'X'], 'picked_quantity' => 7, 'unit_price' => 20, 'discount' => 0, 'quantity' => 7]],
                ]],
                'products' => [['product' => (object) ['name' => 'Producto X', 'code' => 'X'], 'brand' => null, 'quantity' => 12]],
            ]],
        ];

        $consolidated = view('pdf.route-global-preparation.consolidated', compact('document'))->render();
        $products = view('pdf.route-global-preparation.products', compact('document'))->render();
        $receipts = view('pdf.route-global-preparation.receipts', compact('document'))->render();

        $this->assertStringContainsString('VENDEDOR: Carlos', $consolidated);
        $this->assertStringContainsString('Producto X', $products);
        $this->assertStringContainsString('12.00', $products);
        $this->assertStringContainsString('@page { size: 5.5in 8.5in;', $receipts);
        $this->assertStringContainsString('.receipt + .receipt { page-break-before: always;', $receipts);
        $this->assertStringContainsString('Vendedor: Carlos', $receipts);
        $this->assertStringNotContainsString('overflow: hidden', $receipts);
        $this->assertNotSame('', Pdf::loadHTML($receipts)->setPaper([0, 0, 396, 612])->output());
    }

    public function test_global_documents_sum_products_per_seller_across_work_days_without_merging_sellers(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $first = $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '100.00', '5');
        $sharedProduct = $first->items()->firstOrFail()->product;
        $this->preparablePreSale($business, $branch, $workDays[1], $sellerA, '140.00', '7', $sharedProduct);
        $this->preparablePreSale($business, $branch, $workDays[2], $sellerB, '80.00', '4', $sharedProduct);
        $execution = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-document-product-sum-0001');

        $document = app(RouteGlobalPreparationDocuments::class)->forBatches($business->id, $branch->id, array_column($execution['processed'], 'batch_id'));

        $this->assertSame($sellerA->id, $document['sellers'][0]['seller']['id']);
        $this->assertSame(12.0, $document['sellers'][0]['products'][0]['quantity']);
        $this->assertSame($sellerB->id, $document['sellers'][1]['seller']['id']);
        $this->assertSame(4.0, $document['sellers'][1]['products'][0]['quantity']);
    }

    public function test_generate_sales_reuses_real_child_delivery_batches_without_duplicate_sales_or_picking_stock(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        TenantSetting::query()->where('business_id', $business->id)->update([
            'route_collection_responsibility' => 'delivery_agent',
            'route_delivery_tracking' => 'external',
            'route_pre_sale_invoicing_mode' => 'manual',
        ]);
        $preSales = [
            $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00'),
            $this->preparablePreSale($business, $branch, $workDays[1], $sellerA, '40.00'),
            $this->preparablePreSale($business, $branch, $workDays[2], $sellerB, '20.00'),
        ];
        $this->preSale($business->id, $branch->id, $workDays[2]->id, $sellerB->id, '15.00', PreSale::STATUS_CANCELLED);

        app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-sales-prepare-0001');
        $first = app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-sales-0001');
        $salesCount = \App\Models\Sale::query()->where('business_id', $business->id)->count();
        $saleItems = \App\Models\SaleItem::query()->where('business_id', $business->id)->count();
        $movementCount = \App\Models\StockMovement::query()->where('business_id', $business->id)->count();
        $second = app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-sales-0001');
        $newKey = app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-sales-0002');

        $this->assertCount(3, $first['processed']);
        $this->assertSame([], $first['failed']);
        $this->assertSame(3, $salesCount);
        $this->assertSame(3, $saleItems);
        foreach ($preSales as $preSale) {
            $preSale->refresh();
            $this->assertNotNull($preSale->converted_sale_id);
            $this->assertSame($preSale->converted_sale_id, $preSale->convertedSale()->value('id'));
        }
        $this->assertSame($salesCount, \App\Models\Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame($saleItems, \App\Models\SaleItem::query()->where('business_id', $business->id)->count());
        $this->assertSame($movementCount, \App\Models\StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(array_column($first['processed'], 'batch_id'), array_column($second['processed'], 'batch_id'));
        $this->assertSame([], $newKey['processed']);
        $this->assertSame([], $newKey['failed']);
        $this->assertSame(0, \App\Models\SalePayment::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, \App\Models\RouteDeliveryCollection::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, \App\Models\CustomerAccountMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_generate_sales_deducts_invoice_timed_stock_once_and_remains_operational_when_fel_is_off(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        TenantSetting::query()->where('business_id', $business->id)->update([
            'route_pre_sale_stock_deduction_timing' => 'invoice',
            'route_collection_responsibility' => 'delivery_agent',
            'route_delivery_tracking' => 'external',
            'route_pre_sale_invoicing_mode' => 'manual',
        ]);
        $preSale = $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '23.45');
        $productId = $preSale->items()->value('product_id');
        $stockBefore = ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $productId)->value('stock');

        app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-invoice-prepare-0001');
        $stockAfterPreparation = ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $productId)->value('stock');
        app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-invoice-sales-0001');
        $stockAfterSales = ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $productId)->value('stock');
        $movements = \App\Models\StockMovement::query()->where('business_id', $business->id)->where('product_id', $productId)->count();
        app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-invoice-sales-0001');

        $this->assertSame((float) $stockBefore, (float) $stockAfterPreparation);
        $this->assertSame((float) $stockBefore - 1.0, (float) $stockAfterSales);
        $this->assertSame($movements, \App\Models\StockMovement::query()->where('business_id', $business->id)->where('product_id', $productId)->count());
        $this->assertNotNull($preSale->refresh()->converted_sale_id);
        $this->assertSame(0, \App\Models\ElectronicDocument::query()->where('business_id', $business->id)->count());
    }

    public function test_generate_sales_preserves_the_existing_pre_seller_collection_flow(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_collection_responsibility' => 'pre_seller']);
        $preSale = $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-preseller-prepare-0001');
        $preSale->refresh();
        $collectionResult = app(\App\Services\Routes\RoutePreSaleCollectionService::class)->capture($preSale, [
            'amount' => $preSale->total, 'payment_method' => 'cash', 'idempotency_key' => 'global-preseller-collection-0001',
        ], $sellerA);
        $collection = \App\Models\RoutePreSaleCollection::query()->findOrFail($collectionResult->resultId);

        app(RouteGlobalOperationsService::class)->generateSales($actor, 'global-preseller-sales-0001');

        $sale = $preSale->refresh()->convertedSale;
        $this->assertNotNull($sale);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'route_pre_sale_collection_id' => $collection->id]);
        $this->assertDatabaseHas('route_pre_sale_collections', ['id' => $collection->id, 'status' => 'linked']);
    }

    public function test_global_operations_dashboard_is_authorized_and_scoped_to_the_current_branch(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');

        $this->actingAs($actor)->get(route('routes.global-operations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Routes/GlobalOperations/Index')
                ->has('preparation_preview.summary')
                ->has('sales_preview.summary'));

        $denied = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $this->actingAs($denied)->get(route('routes.global-operations.index'))->assertForbidden();
    }

    public function test_global_operation_result_flashes_are_shared_with_inertia_and_keep_exact_child_batch_ids(): void
    {
        [$business, $branch, $actor] = $this->preparationExecutionFixture();
        $preparationResult = [
            'processed' => [['work_day_id' => 41, 'seller_id' => 19, 'seller_name' => 'Carlos', 'batch_id' => 701, 'total_pre_sales' => 3]],
            'blocked' => [['work_day_id' => 42, 'seller_id' => 20, 'seller_name' => 'María', 'reason_code' => 'child_validation_blocked', 'message' => 'No hay caja abierta para operar rutas.']],
            'failed' => [],
        ];
        $salesResult = [
            'processed' => [],
            'blocked' => [['work_day_id' => 41, 'seller_id' => 19, 'seller_name' => 'Carlos', 'reason_code' => 'child_validation_blocked', 'message' => 'Debe registrar el cobro antes de generar el comprobante.']],
            'failed' => [],
        ];

        $this->withSession([
            'active_business_id' => $business->id,
            'global_preparation_result' => $preparationResult,
            'global_sales_result' => $salesResult,
        ])->actingAs($actor)->get(route('routes.global-operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/GlobalOperations/Index')
                ->where('flash.global_preparation_result.processed.0.batch_id', 701)
                ->where('flash.global_preparation_result.processed.0.total_pre_sales', 3)
                ->where('flash.global_preparation_result.blocked.0.message', 'No hay caja abierta para operar rutas.')
                ->where('flash.global_sales_result.blocked.0.message', 'Debe registrar el cobro antes de generar el comprobante.'));
    }

    public function test_global_operation_markup_renders_feedback_downloads_and_prevents_double_submit_while_processing(): void
    {
        $markup = file_get_contents(resource_path('js/Pages/Routes/GlobalOperations/Index.tsx'));

        $this->assertStringContainsString('PREPARACIÓN COMPLETADA', $markup);
        $this->assertStringContainsString('GENERACIÓN DE VENTAS COMPLETADA', $markup);
        $this->assertStringContainsString('{flash.global_preparation_result && <ResultPanel', $markup);
        $this->assertStringContainsString('{flash.global_sales_result && <ResultPanel', $markup);
        $this->assertStringContainsString('routes.global-operations.documents.${name}', $markup);
        $this->assertStringContainsString('flash.global_preparation_result?.processed.map(row => row.batch_id)', $markup);
        $this->assertStringContainsString('useRef(false)', $markup);
        $this->assertStringContainsString('submissionLockedRef.current', $markup);
        $this->assertStringContainsString("setProcessing(operation)", $markup);
        $this->assertStringContainsString("processing ? 'Procesando...' : 'Confirmar'", $markup);
        $this->assertStringContainsString('{resultReason(row)}', $markup);
        $this->assertStringContainsString('return row.message ||', $markup);
    }

    public function test_global_operation_http_prepares_and_downloads_only_the_exact_completed_child_batches(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');

        $this->actingAs($actor)->post(route('routes.global-operations.prepare'), ['idempotency_key' => 'global-http-prepare-0001'])
            ->assertRedirect()
            ->assertSessionHas('global_preparation_result.processed.0.batch_id');
        $batchId = RoutePreparationBatch::query()->where('business_id', $business->id)->value('id');

        $this->actingAs($actor)->get(route('routes.global-operations.documents.receipts', ['batch_ids' => [$batchId]]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($actor)->get(route('routes.global-operations.documents.receipts', ['batch_ids' => [$batchId, 999999]]))
            ->assertSessionHasErrors('batch_ids');
    }

    public function test_global_document_download_http_rejects_foreign_branch_and_business_batch_ids_without_partial_pdf(): void
    {
        [$business, $branch, $actor, $sellerA, $sellerB, $workDays] = $this->preparationExecutionFixture();
        $this->preparablePreSale($business, $branch, $workDays[0], $sellerA, '60.00');
        $validId = app(RouteGlobalOperationsService::class)->prepareAll($actor, 'global-http-scope-0001')['processed'][0]['batch_id'];
        $otherBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Ajena', 'code' => 'AJ-'.uniqid(), 'is_active' => true]);
        $otherBranchDay = $this->workDay($business->id, $otherBranch->id, $sellerA->id);
        $otherBranchBatch = $this->completedBatch($business->id, $otherBranch->id, $otherBranchDay, $actor->id);
        $foreign = Business::query()->create(['name' => 'Negocio ajeno '.uniqid(), 'slug' => 'negocio-ajeno-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $foreignBranch = BranchInventory::defaultBranchForBusiness($foreign);
        $foreignSeller = User::factory()->create(['business_id' => $foreign->id, 'current_branch_id' => $foreignBranch->id, 'is_active' => true]);
        $foreignDay = $this->workDay($foreign->id, $foreignBranch->id, $foreignSeller->id);
        $foreignBatch = $this->completedBatch($foreign->id, $foreignBranch->id, $foreignDay, $foreignSeller->id);

        foreach ([$otherBranchBatch->id, $foreignBatch->id] as $invalidId) {
            $this->actingAs($actor)->get(route('routes.global-operations.documents.consolidated', ['batch_ids' => [$validId, $invalidId]]))
                ->assertSessionHasErrors('batch_ids');
        }
    }

    private function workDay(int $businessId, int $branchId, int $sellerId): RouteWorkDay
    {
        $zone = RouteZone::query()->create(['business_id' => $businessId, 'branch_id' => $branchId, 'assigned_user_id' => $sellerId, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        return RouteWorkDay::query()->create(['business_id' => $businessId, 'branch_id' => $branchId, 'route_zone_id' => $zone->id, 'seller_id' => $sellerId, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
    }

    private function completedBatch(int $businessId, int $branchId, RouteWorkDay $workDay, int $userId): RoutePreparationBatch
    {
        return RoutePreparationBatch::query()->create(['business_id' => $businessId, 'branch_id' => $branchId, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $workDay->route_zone_id, 'prepared_by' => $userId, 'prepared_at' => now(), 'status' => RoutePreparationBatch::STATUS_COMPLETED, 'stock_deduction_timing' => 'picking', 'invoicing_mode' => 'manual', 'total_pre_sales' => 0, 'total_items' => 0, 'total_amount' => 0]);
    }

    private function preSale(int $businessId, int $branchId, int $workDayId, int $sellerId, string $total, string $status): PreSale
    {
        $customer = Customer::query()->create(['business_id' => $businessId, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        return PreSale::query()->create(['business_id' => $businessId, 'branch_id' => $branchId, 'route_work_day_id' => $workDayId, 'customer_id' => $customer->id, 'seller_id' => $sellerId, 'status' => $status, 'subtotal' => $total, 'discount_total' => 0, 'total' => $total, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash', 'payment_method_set_at' => now(), 'payment_method_set_by' => $sellerId]);
    }

    private function preparationExecutionFixture(): array
    {
        $business = Business::query()->create(['name' => 'Execution '.uniqid(), 'slug' => 'execution-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        TenantSetting::query()->create(['business_id' => $business->id, 'route_pre_sale_stock_deduction_timing' => 'picking']);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'routes', 'is_enabled' => true, 'enabled_at' => now()]);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($actor, 'owner');
        $sellerA = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $sellerB = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        return [$business, $branch, $actor, $sellerA, $sellerB, [$this->workDay($business->id, $branch->id, $sellerA->id), $this->workDay($business->id, $branch->id, $sellerA->id), $this->workDay($business->id, $branch->id, $sellerB->id)]];
    }

    private function preparablePreSale(Business $business, $branch, RouteWorkDay $workDay, User $seller, string $total, string $quantity = '1', ?Product $product = null): PreSale
    {
        $preSale = $this->preSale($business->id, $branch->id, $workDay->id, $seller->id, $total, PreSale::STATUS_SUBMITTED);
        $product ??= Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'GLB-'.uniqid(), 'cost_price' => 1, 'sale_price' => $total, 'stock' => 50, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->firstOrCreate(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id], ['stock' => 50]);
        $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => bcdiv($total, $quantity, 4), 'discount' => 0, 'total' => $total]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => $quantity, 'status' => 'active', 'created_by' => $seller->id]);
        return $preSale;
    }
}
