<?php

namespace Tests\Feature;

use App\Exceptions\ProductCostRecalculationSnapshotChanged;
use App\Models\Branch;
use App\Models\Business;
use App\Models\HistoricalProductPurchaseCost;
use App\Models\Product;
use App\Models\ProductCostRecalculationRun;
use App\Models\Supplier;
use App\Models\User;
use App\Services\HistoricalProductCostAverageService;
use App\Services\ProductCostRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCostRecalculationTest extends TestCase
{
    use RefreshDatabase;

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
        ]);
        $this->assertDatabaseMissing('product_cost_recalculation_items', ['product_id' => $withoutHistory->id]);
        $this->assertDatabaseMissing('product_cost_recalculation_items', ['product_id' => $ambiguous->id]);
    }

    public function test_application_rejects_a_changed_snapshot_without_writing_any_cost(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('10.00000');
        $this->historical($business, $branch, $product, $supplier, '1.00000', '20.00000', 'before-change');
        $summary = app(HistoricalProductCostAverageService::class)->summary($business->id);
        $this->historical($business, $branch, $product, $supplier, '1.00000', '30.00000', 'after-change');

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

    private function historical(Business $business, Branch $branch, Product $product, Supplier $supplier, string $quantity, string $cost, string $recordId, string $source = 'legacy'): void
    {
        HistoricalProductPurchaseCost::query()->create([
            'business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id,
            'acquired_at' => '2026-01-01 12:00:00', 'quantity' => $quantity, 'unit_cost' => $cost,
            'supplier_invoice_number' => 'H-'.$recordId, 'source_system' => $source, 'source_record_id' => $recordId, 'imported_at' => now(),
        ]);
    }
}
