<?php

namespace Tests\Feature;

use App\Exceptions\ProductCostRecalculationSnapshotChanged;
use App\Exceptions\ProductCostRecalculationRequiresOwnTransaction;
use App\Models\Branch;
use App\Models\Business;
use App\Models\HistoricalProductPurchaseCost;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductCostRecalculationRun;
use App\Models\Supplier;
use App\Models\User;
use App\Services\HistoricalProductCostAverageService;
use App\Services\ProductCostRecalculationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCostRecalculationTest extends TestCase
{
    // DatabaseTruncation uses phpunit.xml's blunk_test connection without
    // wrapping each test in a transaction, so apply() owns SERIALIZABLE.
    use DatabaseTruncation;

    public function test_tool_is_exclusive_to_super_admins(): void
    {
        $tenantUser = User::factory()->create(['is_super_admin' => false]);
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($tenantUser)
            ->get(route('super-admin.tools.product-cost-recalculation.index'))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get(route('super-admin.tools.product-cost-recalculation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/ProductCostRecalculation/Index')
                ->where('summary', null));
    }

    public function test_simulation_is_read_only_and_application_updates_only_calculable_changed_costs_with_audit(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('10.00000');
        $withoutHistory = $this->product($business, 'Sin historial', '5.00000');
        $ambiguous = $this->product($business, 'Ambiguo', '2.00000');
        $this->stock($business, $branch, $product, '2.00000');
        $this->stock($business, $branch, $ambiguous, '1.00000');
        $this->historical($business, $branch, $product, $supplier, '2.00000', '15.123456', 'acquisition-1');
        $this->historical($business, $branch, $ambiguous, $supplier, '1.00000', '9.00000', 'missing-native', 'blunk');
        $actor = User::factory()->create(['is_super_admin' => true]);

        $averages = app(HistoricalProductCostAverageService::class);
        $summary = $averages->summary($business->id);

        $this->assertSame('10.00000', $product->fresh()->cost_price);
        $this->assertSame('5.00000', $withoutHistory->fresh()->cost_price);
        $this->assertSame('2.00000', $ambiguous->fresh()->cost_price);

        $run = app(ProductCostRecalculationService::class)->apply($business, $actor, [
            'fingerprint' => $summary['fingerprint'],
            'different' => $summary['different'],
        ]);

        $this->assertSame(ProductCostRecalculationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('15.12346', $product->fresh()->cost_price);
        $this->assertSame('5.00000', $withoutHistory->fresh()->cost_price);
        $this->assertSame('2.00000', $ambiguous->fresh()->cost_price);
        $this->assertDatabaseHas('product_cost_recalculation_items', [
            'product_cost_recalculation_run_id' => $run->id,
            'product_id' => $product->id,
            'previous_cost' => '10.00000',
            'proposed_cost' => '15.12346',
            'physical_stock' => '2.00000',
            'covered_quantity' => '2.00000',
        ]);
        $this->assertDatabaseMissing('product_cost_recalculation_items', ['product_id' => $withoutHistory->id]);
        $this->assertDatabaseMissing('product_cost_recalculation_items', ['product_id' => $ambiguous->id]);
    }

    public function test_application_rejects_a_stock_change_after_simulation_without_writing_any_cost(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('10.00000');
        $this->stock($business, $branch, $product, '1.00000');
        $this->historical($business, $branch, $product, $supplier, '1.00000', '20.00000', 'before-change');
        $summary = app(HistoricalProductCostAverageService::class)->summary($business->id);
        $this->stock($business, $branch, $product, '2.00000');

        try {
            app(ProductCostRecalculationService::class)->apply($business, User::factory()->create(['is_super_admin' => true]), [
                'fingerprint' => $summary['fingerprint'],
                'different' => $summary['different'],
            ]);
            $this->fail('Expected stale simulation to be rejected.');
        } catch (ProductCostRecalculationSnapshotChanged) {
            $this->assertSame('10.00000', $product->fresh()->cost_price);
            $this->assertDatabaseHas('product_cost_recalculation_runs', [
                'business_id' => $business->id,
                'status' => ProductCostRecalculationRun::STATUS_FAILED,
            ]);
            $this->assertDatabaseCount('product_cost_recalculation_items', 0);
        }
    }

    public function test_application_refuses_an_existing_outer_transaction_without_weaker_isolation(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('10.00000');
        $this->stock($business, $branch, $product, '1.00000');
        $this->historical($business, $branch, $product, $supplier, '1.00000', '20.00000', 'outer-transaction');
        $summary = app(HistoricalProductCostAverageService::class)->summary($business->id);

        DB::beginTransaction();

        try {
            app(ProductCostRecalculationService::class)->apply($business, User::factory()->create(['is_super_admin' => true]), [
                'fingerprint' => $summary['fingerprint'],
                'different' => $summary['different'],
            ]);
            $this->fail('Expected an outer transaction to be refused.');
        } catch (ProductCostRecalculationRequiresOwnTransaction) {
            $this->assertSame('10.00000', $product->fresh()->cost_price);
            $this->assertDatabaseCount('product_cost_recalculation_runs', 0);
        } finally {
            DB::rollBack();
        }
    }

    public function test_uses_newest_acquisitions_until_current_stock_is_covered_with_a_partial_row(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('100.00000');
        $otherBranch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Secundaria', 'code' => 'SEC', 'is_active' => true]);
        $this->stock($business, $branch, $product, '5.00000');
        $this->stock($business, $otherBranch, $product, '3.00000');
        $this->historical($business, $branch, $product, $supplier, '4.00000', '332.61000', 'recent', 'legacy', '2026-03-03 12:00:00');
        $this->historical($business, $branch, $product, $supplier, '2.00000', '375.37000', 'middle', 'legacy', '2026-03-02 12:00:00');
        $this->historical($business, $branch, $product, $supplier, '6.00000', '292.50000', 'old', 'legacy', '2026-03-01 12:00:00');

        $row = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();

        $this->assertSame('8.00000', $row['physical_stock']);
        $this->assertSame('8.00000', $row['covered_quantity']);
        $this->assertSame('0.00000', $row['missing_quantity']);
        $this->assertSame('333.27250', $row['proposed_cost']);
        $this->assertCount(3, $row['selected_acquisitions']);
        $this->assertTrue($row['selected_acquisitions'][2]['partial']);
        $this->assertSame('2.00000', $row['selected_acquisitions'][2]['taken_quantity']);
    }

    public function test_distinguishes_insufficient_zero_and_negative_physical_stock(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('5.00000');
        $this->stock($business, $branch, $product, '3.00000');
        $this->historical($business, $branch, $product, $supplier, '2.00000', '5.00000', 'short');
        $insufficient = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();
        $this->assertSame('historial_insuficiente', $insufficient['status']);
        $this->assertSame('1.00000', $insufficient['missing_quantity']);

        $this->stock($business, $branch, $product, '0.00000');
        $zero = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();
        $this->assertSame('sin_existencias', $zero['status']);

        $this->stock($business, $branch, $product, '-1.00000');
        $negative = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();
        $this->assertSame('stock_negativo', $negative['status']);
    }

    /** @return array{Business, Branch, Product, Supplier} */
    private function context(string $cost): array
    {
        $business = Business::query()->create([
            'name' => 'Recalculo '.uniqid(), 'slug' => 'recalculo-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true,
        ]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor']);

        return [$business, $branch, $this->product($business, 'Producto CPP', $cost), $supplier];
    }

    private function product(Business $business, string $name, string $cost): Product
    {
        return Product::query()->create([
            'business_id' => $business->id, 'name' => $name, 'code' => 'CPP-'.uniqid(), 'cost_price' => $cost,
            'sale_price' => '20.00', 'stock' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    private function historical(Business $business, Branch $branch, Product $product, Supplier $supplier, string $quantity, string $cost, string $recordId, string $source = 'legacy', string $acquiredAt = '2026-01-01 12:00:00'): void
    {
        HistoricalProductPurchaseCost::query()->create([
            'business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id,
            'acquired_at' => $acquiredAt, 'quantity' => $quantity, 'unit_cost' => $cost,
            'supplier_invoice_number' => 'H-'.$recordId, 'source_system' => $source, 'source_record_id' => $recordId, 'imported_at' => now(),
        ]);
    }

    private function stock(Business $business, Branch $branch, Product $product, string $quantity): void
    {
        ProductBranchStock::query()->updateOrCreate([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
        ], ['stock' => $quantity]);
    }
}
