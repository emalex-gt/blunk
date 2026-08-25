<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Routes\RoutePreparationBatchService;
use App\Services\Routes\RoutePreSalePreparationService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoutePreparationBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_route_preparation_schema_preserves_compatible_defaults(): void
    {
        $this->assertTrue(Schema::hasColumn('tenant_settings', 'route_pre_sale_stock_deduction_timing'));
        $this->assertTrue(Schema::hasColumn('pre_sale_items', 'stock_deducted_quantity'));
        $this->assertTrue(Schema::hasTable('route_preparation_batches'));
        $this->assertTrue(Schema::hasTable('route_preparation_batch_pre_sales'));

        $business = Business::query()->create([
            'name' => 'Preparation defaults '.uniqid(),
            'slug' => 'preparation-defaults-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        $settings = TenantSetting::query()->create(['business_id' => $business->id])->fresh();

        $this->assertSame('invoice', $settings->route_pre_sale_stock_deduction_timing);
        $this->assertSame('manual', $settings->route_pre_sale_invoicing_mode);
    }

    public function test_batch_membership_cannot_include_the_same_pre_sale_twice(): void
    {
        $indexes = collect(Schema::getIndexes('route_preparation_batch_pre_sales'));

        $this->assertTrue($indexes->contains(fn (array $index) =>
            ($index['name'] ?? null) === 'route_preparation_batch_pre_sales_unique'
            && ($index['unique'] ?? false) === true,
        ));
    }

    public function test_invoice_timing_prepares_without_decreasing_stock_or_consuming_reservation(): void
    {
        [$business, $branch, $user, $preSale, $item, $product] = $this->preSale('invoice');

        DB::transaction(fn () => app(RoutePreSalePreparationService::class)->prepare($preSale, [[
            'id' => $item->id,
            'picked_quantity' => 3,
        ]], $user, 'invoice'));

        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
        $this->assertSame(3.0, (float) $item->refresh()->picked_quantity);
        $this->assertSame(0.0, (float) $item->stock_deducted_quantity);
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(3.0, (float) StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->sum('quantity'));
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_picking_timing_decreases_stock_records_movement_and_consumes_reservation(): void
    {
        [$business, $branch, $user, $preSale, $item, $product] = $this->preSale('picking');

        DB::transaction(fn () => app(RoutePreSalePreparationService::class)->prepare($preSale, [[
            'id' => $item->id,
            'picked_quantity' => 3,
        ]], $user, 'picking'));

        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
        $this->assertSame(3.0, (float) $item->refresh()->stock_deducted_quantity);
        $this->assertSame(7.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('stock_movements', ['business_id' => $business->id, 'product_id' => $product->id, 'type' => 'pre_sale_picking', 'quantity' => -3]);
        $this->assertSame(0, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'consumed')->count());
    }

    public function test_prepare_all_is_atomic_when_one_pre_sale_has_an_invalid_reservation(): void
    {
        [$business, $branch, $user, $first] = $this->preSale('picking');
        [, , , $second] = $this->preSale('picking', $business, $branch, $user);
        $second->update(['route_work_day_id' => $first->route_work_day_id, 'route_zone_id' => $first->route_zone_id]);
        StockReservation::query()->where('source_id', $second->id)->update(['quantity' => 0]);

        $this->actingAs($user);

        try {
            app(RoutePreparationBatchService::class)->prepareAll($first->workDay, $user, 'route-prepare-all-invalid-key');
            $this->fail('Expected preparation validation to fail.');
        } catch (ValidationException) {
            // Expected: the entire database transaction must be rolled back.
        }

        $this->assertSame(PreSale::STATUS_SUBMITTED, $first->refresh()->status);
        $this->assertSame(PreSale::STATUS_SUBMITTED, $second->refresh()->status);
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->value('stock'));
        $this->assertDatabaseCount('route_preparation_batches', 0);
        $this->assertDatabaseCount('route_preparation_batch_pre_sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_prepare_all_creates_one_completed_batch_and_replays_the_same_key(): void
    {
        [$business, $branch, $user, $first] = $this->preSale('invoice');
        [, , , $second] = $this->preSale('invoice', $business, $branch, $user);
        $second->update(['route_work_day_id' => $first->route_work_day_id, 'route_zone_id' => $first->route_zone_id]);
        $this->actingAs($user);

        $result = app(RoutePreparationBatchService::class)->prepareAll($first->workDay, $user, 'route-prepare-all-replay-key');
        $replay = app(RoutePreparationBatchService::class)->prepareAll($first->workDay, $user, 'route-prepare-all-replay-key');

        $this->assertFalse($result->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($result->resultId, $replay->resultId);
        $this->assertDatabaseHas('route_preparation_batches', ['id' => $result->resultId, 'business_id' => $business->id, 'branch_id' => $branch->id, 'status' => 'completed', 'total_pre_sales' => 2]);
        $this->assertSame(2, \App\Models\RoutePreparationBatchPreSale::query()->where('route_preparation_batch_id', $result->resultId)->count());
        $this->assertSame(PreSale::STATUS_PICKED, $first->refresh()->status);
        $this->assertSame(PreSale::STATUS_PICKED, $second->refresh()->status);
    }

    public function test_prepare_all_route_and_work_day_props_are_limited_to_the_active_branch(): void
    {
        [$business, $branch, $user, $preSale] = $this->preSale('invoice');
        $this->actingAs($user)
            ->get(route('routes.work-days.show', $preSale->workDay))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/WorkDays/Show')
                ->where('preparation.can_prepare_all', true)
                ->where('preparation.preparable_count', 1)
                ->where('preparation.stock_deduction_timing', 'invoice'));

        $this->actingAs($user)
            ->post(route('routes.work-days.prepare-all', $preSale->workDay), ['idempotency_key' => 'route-prepare-all-http-key'])
            ->assertRedirect();

        $this->assertSame(1, \App\Models\RoutePreparationBatch::query()->where('business_id', $business->id)->count());
    }

    public function test_preparation_documents_are_available_only_to_the_active_tenant_branch(): void
    {
        [, , $user, $preSale] = $this->preSale('invoice');
        $this->actingAs($user);
        $result = app(RoutePreparationBatchService::class)->prepareAll($preSale->workDay, $user, 'route-prepare-all-documents-key');

        foreach (['consolidated', 'receipts', 'products'] as $document) {
            $this->get(route("routes.preparation-batches.documents.{$document}", $result->resultId))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_automatic_all_is_snapshotted_without_creating_sales_or_fel_documents(): void
    {
        [$business, , $user, $preSale] = $this->preSale('invoice');
        TenantSetting::query()->where('business_id', $business->id)->update(['route_pre_sale_invoicing_mode' => 'automatic_all']);
        $this->actingAs($user);

        $result = app(RoutePreparationBatchService::class)->prepareAll($preSale->workDay, $user, 'route-prepare-all-automatic-key');

        $this->assertDatabaseHas('route_preparation_batches', ['id' => $result->resultId, 'invoicing_mode' => 'automatic_all']);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('electronic_documents', 0);
    }

    public function test_prepare_all_and_documents_cannot_cross_tenants(): void
    {
        [, , $userA] = $this->preSale('invoice');
        [, , $userB, $preSaleB] = $this->preSale('invoice');

        $this->actingAs($userA)
            ->post(route('routes.work-days.prepare-all', $preSaleB->workDay), ['idempotency_key' => 'route-prepare-all-other-tenant-key'])
            ->assertForbidden();

        $this->actingAs($userB);
        $result = app(RoutePreparationBatchService::class)->prepareAll($preSaleB->workDay, $userB, 'route-prepare-all-tenant-document-key');

        $this->actingAs($userA)
            ->get(route('routes.preparation-batches.documents.consolidated', $result->resultId))
            ->assertForbidden();
    }

    private function preSale(string $timing, ?Business $business = null, $branch = null, ?User $user = null): array
    {
        $business ??= Business::query()->create([
            'name' => 'Preparation '.uniqid(),
            'slug' => 'preparation-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $branch ??= BranchInventory::defaultBranchForBusiness($business);
        $user ??= User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
            'is_active' => true,
        ]);
        Permissions::assignRole($user, 'owner');

        TenantSetting::query()->updateOrCreate(['business_id' => $business->id], [
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'allow_negative_stock' => false,
            'route_pre_sale_stock_deduction_timing' => $timing,
        ]);
        TenantModule::query()->firstOrCreate(['business_id' => $business->id, 'module' => 'routes'], ['is_enabled' => true, 'enabled_at' => now()]);

        $zone = RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $user->id,
            'name' => 'Zona '.uniqid(),
            'is_active' => true,
        ]);
        $workDay = RouteWorkDay::query()->firstOrCreate([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $user->id,
            'work_date' => today(),
        ], ['status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF', 'country' => 'GT']);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'PREP-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => PreSale::STATUS_SUBMITTED, 'subtotal' => 60, 'discount_total' => 0, 'total' => 60, 'submitted_at' => now()]);
        $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 20, 'discount' => 0, 'total' => 60]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $user->id]);

        return [$business, $branch, $user, $preSale, $item, $product];
    }
}
