<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashMovement;
use App\Models\HistoricalProductPurchaseCost;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Support\ProductSupplierCostHistory;
use App\Support\ProductPurchaseCostHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPurchaseCostHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_costs_are_documentary_and_feed_the_existing_supplier_cost_contract(): void
    {
        [$business, $branch, $product, $supplier] = $this->context();
        $before = [
            'stock' => $product->stock,
            'cost' => $product->cost_price,
            'stocks' => ProductBranchStock::query()->count(),
            'purchases' => Purchase::query()->count(),
            'items' => PurchaseItem::query()->count(),
            'cash' => CashMovement::query()->count(),
        ];

        $this->historical($business, $product, $supplier, '2025-01-01 12:00:00', 10, 'legacy-1', $branch);

        $cost = ProductSupplierCostHistory::forProducts($business->id, [$product->id])
            ->get($product->id)
            ->sole();

        $this->assertSame($supplier->id, $cost->supplier_id);
        $this->assertSame(10.0, (float) $cost->unit_cost);
        $this->assertNull($cost->purchase_id);
        $this->assertNull($cost->purchase_number);
        $this->assertSame('ferrymas', $cost->source_system);
        $this->assertSame($before['stock'], $product->fresh()->stock);
        $this->assertSame($before['cost'], $product->fresh()->cost_price);
        $this->assertSame($before['stocks'], ProductBranchStock::query()->count());
        $this->assertSame($before['purchases'], Purchase::query()->count());
        $this->assertSame($before['items'], PurchaseItem::query()->count());
        $this->assertSame($before['cash'], CashMovement::query()->count());
    }

    public function test_latest_supplier_cost_uses_acquired_at_not_import_time_for_native_and_historical_rows(): void
    {
        [$business, $branch, $product, $supplier] = $this->context();
        $this->historical($business, $product, $supplier, '2020-01-01 12:00:00', 5, 'old-import', $branch, importedAt: now());
        $this->purchase($business, $branch, $product, $supplier, '2026-01-01 12:00:00', 12);

        $cost = ProductSupplierCostHistory::forProducts($business->id, [$product->id])->get($product->id)->sole();
        $this->assertSame(12.0, (float) $cost->unit_cost);
        $this->assertSame('blunk', $cost->source_system);

        $this->historical($business, $product, $supplier, '2027-01-01 12:00:00', 13, 'new-history', $branch);
        $cost = ProductSupplierCostHistory::forProducts($business->id, [$product->id])->get($product->id)->sole();
        $this->assertSame(13.0, (float) $cost->unit_cost);
        $this->assertSame('ferrymas', $cost->source_system);
    }

    public function test_native_purchase_wins_an_exact_timestamp_tie_and_cancelled_purchases_are_excluded(): void
    {
        [$business, $branch, $product, $supplier] = $this->context();
        $timestamp = '2026-06-01 09:00:00';
        $this->historical($business, $product, $supplier, $timestamp, 11, 'same-date', $branch);
        $this->purchase($business, $branch, $product, $supplier, $timestamp, 12);
        $this->purchase($business, $branch, $product, $supplier, '2028-01-01 09:00:00', 99, status: 'cancelled');

        $cost = ProductSupplierCostHistory::forProducts($business->id, [$product->id])->get($product->id)->sole();
        $this->assertSame(12.0, (float) $cost->unit_cost);
        $this->assertSame('blunk', $cost->source_system);
    }

    public function test_supplier_costs_are_independent_and_rows_without_supplier_stay_out_of_supplier_history(): void
    {
        [$business, $branch, $product, $supplierA] = $this->context();
        $supplierB = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor B']);
        $this->historical($business, $product, $supplierA, '2026-01-01 12:00:00', 10, 'supplier-a', $branch);
        $this->historical($business, $product, $supplierB, '2025-01-01 12:00:00', 20, 'supplier-b', $branch);
        $this->historical($business, $product, null, '2027-01-01 12:00:00', 30, 'without-supplier', $branch);

        $costs = ProductSupplierCostHistory::forProducts($business->id, [$product->id])->get($product->id);
        $this->assertCount(2, $costs);
        $this->assertSame([$supplierA->id, $supplierB->id], $costs->pluck('supplier_id')->sort()->values()->all());
    }

    public function test_historical_costs_are_isolated_by_business(): void
    {
        [$businessA, $branchA, $productA, $supplierA] = $this->context();
        [$businessB, $branchB, $productB, $supplierB] = $this->context();
        $this->historical($businessA, $productA, $supplierA, '2026-01-01 12:00:00', 10, 'a', $branchA);
        $this->historical($businessB, $productB, $supplierB, '2026-01-01 12:00:00', 99, 'b', $branchB);

        $rows = ProductPurchaseCostHistory::reportRows($businessA->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($productA->id, $rows->sole()->product_id);
    }

    public function test_historical_cost_contract_preserves_five_decimal_unit_costs(): void
    {
        [$business, $branch, $product, $supplier] = $this->context();
        $this->historical($business, $product, $supplier, '2026-01-01 12:00:00', 12.34567, 'five-decimals', $branch);

        $cost = ProductSupplierCostHistory::forProducts($business->id, [$product->id])->get($product->id)->sole();

        $this->assertSame(12.34567, (float) $cost->unit_cost);
    }

    /** @return array{Business, Branch, Product, Supplier} */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'Costos '.uniqid(),
            'slug' => 'costs-'.uniqid(),
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
            'name' => 'Producto costo',
            'code' => 'COST-'.uniqid(),
            'cost_price' => 1,
            'sale_price' => 2,
            'stock' => 7,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 7,
        ]);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);

        return [$business, $branch, $product, $supplier];
    }

    private function historical(Business $business, Product $product, ?Supplier $supplier, string $acquiredAt, float $cost, string $recordId, Branch $branch, mixed $importedAt = null): HistoricalProductPurchaseCost
    {
        return HistoricalProductPurchaseCost::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier?->id,
            'acquired_at' => $acquiredAt,
            'quantity' => 1,
            'unit_cost' => $cost,
            'supplier_invoice_number' => 'F-'.$recordId,
            'source_system' => 'ferrymas',
            'source_record_id' => $recordId,
            'imported_at' => $importedAt ?? now(),
        ]);
    }

    private function purchase(Business $business, Branch $branch, Product $product, Supplier $supplier, string $createdAt, float $cost, string $status = 'completed'): Purchase
    {
        $purchase = Purchase::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => 'P-'.uniqid(),
            'status' => $status,
            'total' => $cost,
        ]);
        $purchase->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        PurchaseItem::query()->create([
            'business_id' => $business->id,
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost' => $cost,
            'previous_cost' => 1,
            'new_average_cost' => $cost,
            'total' => $cost,
        ]);

        return $purchase;
    }
}
