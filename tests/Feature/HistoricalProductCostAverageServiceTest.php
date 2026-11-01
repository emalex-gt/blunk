<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\HistoricalProductPurchaseCost;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Services\HistoricalProductCostAverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalProductCostAverageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulates_weighted_historical_average_with_five_decimal_precision_without_updating_product(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('10.00000');
        $this->purchase($business, $branch, $product, $supplier, '2.00000', '10.00000');
        $this->historical($business, $branch, $product, $supplier, '3.00000', '20.00000', 'legacy-1');

        $result = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();

        $this->assertSame('calculable', $result['status']);
        $this->assertSame('16.00000', $result['proposed_cost']);
        $this->assertSame('6.00000', $result['difference']);
        $this->assertSame('5.00000', $result['weighted_quantity']);
        $this->assertSame(2, $result['acquisition_count']);
        $this->assertSame('10.00000', $product->fresh()->cost_price);
    }

    public function test_simulation_is_tenant_scoped_and_ignores_invalid_acquisitions(): void
    {
        [$businessA, $branchA, $productA, $supplierA] = $this->context('1.00000');
        [$businessB, $branchB, $productB, $supplierB] = $this->context('1.00000');
        $this->historical($businessA, $branchA, $productA, $supplierA, '1.00000', '4.00000', 'valid-a');
        $this->historical($businessA, $branchA, $productA, $supplierA, '0.00000', '99.00000', 'zero-quantity');
        $this->historical($businessA, $branchA, $productA, $supplierA, '2.00000', '-1.00000', 'negative-cost');
        $this->historical($businessB, $branchB, $productB, $supplierB, '1.00000', '99.00000', 'valid-b');

        $result = app(HistoricalProductCostAverageService::class)->simulate($businessA->id)->sole();

        $this->assertSame($productA->id, $result['product_id']);
        $this->assertSame('4.00000', $result['proposed_cost']);
        $this->assertSame(1, $result['acquisition_count']);
        $this->assertSame(2, $result['invalid_acquisition_count']);
    }

    public function test_native_and_historical_blunk_duplicate_is_counted_once_but_unmatched_blunk_row_is_ambiguous(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('1.00000');
        $item = $this->purchase($business, $branch, $product, $supplier, '2.00000', '10.00000');
        $this->historical($business, $branch, $product, $supplier, '2.00000', '10.00000', (string) $item->id, 'blunk');

        $deduplicated = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();

        $this->assertSame('calculable', $deduplicated['status']);
        $this->assertSame(1, $deduplicated['acquisition_count']);
        $this->assertSame(1, $deduplicated['duplicate_acquisition_count']);

        $this->historical($business, $branch, $product, $supplier, '1.00000', '9.00000', 'missing-native-item', 'blunk');

        $ambiguous = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();

        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertNull($ambiguous['proposed_cost']);
        $this->assertSame(1, $ambiguous['ambiguous_acquisition_count']);
    }

    public function test_zero_cost_is_valid_and_branch_acquisitions_are_consolidated_with_final_rounding(): void
    {
        [$business, $branch, $product, $supplier] = $this->context('1.00000');
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Secundaria',
            'code' => 'SEC',
            'is_active' => true,
        ]);
        $this->purchase($business, $branch, $product, $supplier, '1.00000', '0.00000');
        $this->purchase($business, $otherBranch, $product, $supplier, '2.00000', '10.00000');

        $result = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();

        $this->assertSame('6.66667', $result['proposed_cost']);
        $this->assertSame('3.00000', $result['weighted_quantity']);
        $this->assertSame(2, $result['acquisition_count']);
    }

    public function test_product_without_history_is_reported_without_proposing_zero_cost(): void
    {
        [$business, , $product] = $this->context('7.50000');

        $result = app(HistoricalProductCostAverageService::class)->simulate($business->id)->sole();

        $this->assertSame($product->id, $result['product_id']);
        $this->assertSame('sin_historial', $result['status']);
        $this->assertNull($result['proposed_cost']);
        $this->assertSame('7.50000', $result['current_cost']);
    }

    /** @return array{Business, Branch, Product, Supplier} */
    private function context(string $cost): array
    {
        $business = Business::query()->create([
            'name' => 'CPP '.uniqid(),
            'slug' => 'cpp-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $branch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Principal',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto CPP',
            'code' => 'CPP-'.uniqid(),
            'cost_price' => $cost,
            'sale_price' => 20,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $supplier = Supplier::query()->create([
            'business_id' => $business->id,
            'name' => 'Proveedor CPP',
        ]);

        return [$business, $branch, $product, $supplier];
    }

    private function purchase(Business $business, Branch $branch, Product $product, Supplier $supplier, string $quantity, string $cost): PurchaseItem
    {
        $purchase = Purchase::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => 'CPP-'.uniqid(),
            'status' => 'completed',
            'total' => bcmul($quantity, $cost, 5),
        ]);

        return PurchaseItem::query()->create([
            'business_id' => $business->id,
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_cost' => $cost,
            'previous_cost' => $product->cost_price,
            'new_average_cost' => $cost,
            'total' => bcmul($quantity, $cost, 5),
        ]);
    }

    private function historical(Business $business, Branch $branch, Product $product, Supplier $supplier, string $quantity, string $cost, string $recordId, string $source = 'ferrymas'): void
    {
        HistoricalProductPurchaseCost::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'acquired_at' => '2026-01-01 12:00:00',
            'quantity' => $quantity,
            'unit_cost' => $cost,
            'supplier_invoice_number' => 'H-'.$recordId,
            'source_system' => $source,
            'source_record_id' => $recordId,
            'imported_at' => now(),
        ]);
    }
}
