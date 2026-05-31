<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\CashExpense;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_report_date_range_over_three_months_is_rejected(): void
    {
        [$business, $user] = $this->tenant();

        $this->actingAs($user)
            ->from(route('reports.profit'))
            ->get(route('reports.profit', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-05-01',
            ]))
            ->assertRedirect(route('reports.profit'))
            ->assertSessionHasErrors([
                'date_from' => 'El rango máximo permitido es de 3 meses.',
            ]);
    }

    public function test_inventory_report_is_branch_scoped(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $product = $this->product($business, $main, stock: 5);

        ProductBranchStock::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $other->id, 'product_id' => $product->id],
            ['stock' => 20],
        );

        $this->actingAs($user)
            ->get(route('reports.inventory'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.stock', 5)
                ->where('rows.data.0.available', 5)
                ->where('branch.id', $main->id));
    }

    public function test_daily_report_cash_summary_uses_branch_cash_movements(): void
    {
        [$business, $user, $main] = $this->tenant();
        $product = $this->product($business, $main, stock: 10, salePrice: 100);
        $session = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 50,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
        $this->sale($business, $main, $user, $product, total: 100, method: 'cash');
        Purchase::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'status' => 'completed',
            'total' => 30,
            'payment_method' => 'cash',
            'paid_from_cash' => true,
            'cash_register_session_id' => $session->id,
            'created_by' => $user->id,
        ]);
        CashExpense::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'cash_register_session_id' => $session->id,
            'description' => 'Gasto',
            'amount' => 10,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('reports.daily', ['date' => now()->toDateString(), 'payment_method' => 'cash']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('summary.0.value', 50)
                ->where('summary.1.value', 100)
                ->where('summary.2.value', 30)
                ->where('summary.3.value', 10)
                ->where('summary.4.value', 110));
    }

    public function test_daily_report_non_cash_ignores_cash_only_movements(): void
    {
        [$business, $user, $main] = $this->tenant();
        $product = $this->product($business, $main, stock: 10, salePrice: 80);
        $this->sale($business, $main, $user, $product, total: 80, method: 'card');

        $this->actingAs($user)
            ->get(route('reports.daily', ['date' => now()->toDateString(), 'payment_method' => 'card']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('summary.0.hidden', true)
                ->where('summary.1.value', 80)
                ->where('summary.4.value', 80));
    }

    public function test_profit_report_uses_stored_sale_line_cost_snapshot(): void
    {
        [$business, $user, $main] = $this->tenant();
        $product = $this->product($business, $main, stock: 10, salePrice: 200);
        $this->sale($business, $main, $user, $product, total: 200, unitCost: 123, profit: 77);
        $product->update(['cost_price' => 999]);

        $this->actingAs($user)
            ->get(route('reports.profit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('summary.1.value', 123)
                ->where('summary.2.value', 77)
                ->where('rows.data.0.cost', 123)
                ->where('rows.data.0.profit', 77));
    }

    public function test_warehouse_money_uses_active_branch_stock_and_default_price(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $product = $this->product($business, $main, stock: 4, salePrice: 25);
        ProductBranchStock::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $other->id, 'product_id' => $product->id],
            ['stock' => 99],
        );

        $this->actingAs($user)
            ->get(route('reports.warehouse-money'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.stock', 4)
                ->where('rows.data.0.sale_price', 25)
                ->where('rows.data.0.total_sale', 100));
    }

    public function test_sales_by_seller_is_branch_scoped(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $product = $this->product($business, $main, stock: 10, salePrice: 100);
        $this->sale($business, $main, $user, $product, total: 100, method: 'cash');
        $this->sale($business, $other, $user, $product, total: 500, method: 'cash');

        $this->actingAs($user)
            ->get(route('reports.sales-by-seller'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.total', '100.00'));
    }

    public function test_sales_detailed_report_is_branch_scoped(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $productA = $this->product($business, $main, name: 'Branch A product', stock: 10, salePrice: 100);
        $productB = $this->product($business, $other, name: 'Branch B product', stock: 10, salePrice: 100);
        $this->sale($business, $main, $user, $productA, total: 100, method: 'cash');
        $this->sale($business, $other, $user, $productB, total: 100, method: 'cash');

        $this->actingAs($user)
            ->get(route('reports.sales-detailed'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.product', 'Branch A product')
                ->where('rows.total', 1));
    }

    public function test_sales_by_date_is_branch_scoped_and_rejects_large_range(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $product = $this->product($business, $main, stock: 10, salePrice: 100);
        $this->sale($business, $main, $user, $product, total: 100, method: 'cash');
        $this->sale($business, $other, $user, $product, total: 500, method: 'cash');

        $this->actingAs($user)
            ->get(route('reports.sales-by-date'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.sales_count', 1)
                ->where('rows.data.0.total', '100.00'));

        $this->actingAs($user)
            ->from(route('reports.sales-by-date'))
            ->get(route('reports.sales-by-date', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-05-01',
            ]))
            ->assertRedirect(route('reports.sales-by-date'))
            ->assertSessionHasErrors([
                'date_from' => 'El rango máximo permitido es de 3 meses.',
            ]);
    }

    public function test_products_sold_detailed_is_branch_scoped(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $productA = $this->product($business, $main, name: 'Detalle A', stock: 10, salePrice: 100);
        $productB = $this->product($business, $other, name: 'Detalle B', stock: 10, salePrice: 100);
        $this->sale($business, $main, $user, $productA, total: 100, method: 'cash');
        $this->sale($business, $other, $user, $productB, total: 100, method: 'cash');

        $this->actingAs($user)
            ->get(route('reports.products-sold-detailed'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.product', 'Detalle A')
                ->where('rows.total', 1));
    }

    public function test_products_sold_summary_is_branch_scoped_and_groups_products(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $product = $this->product($business, $main, name: 'Resumen A', stock: 10, salePrice: 100);
        $otherProduct = $this->product($business, $other, name: 'Resumen B', stock: 10, salePrice: 100);
        $this->sale($business, $main, $user, $product, total: 100, method: 'cash');
        $this->sale($business, $main, $user, $product, total: 150, method: 'cash');
        $this->sale($business, $other, $user, $otherProduct, total: 900, method: 'cash');

        $this->actingAs($user)
            ->get(route('reports.products-sold-summary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.product', 'Resumen A')
                ->where('rows.data.0.quantity', 2)
                ->where('rows.data.0.total', '250.00')
                ->where('rows.total', 1));
    }

    public function test_sales_by_customer_searches_by_name_and_is_branch_scoped(): void
    {
        [$business, $user, $main, $other] = $this->tenant();
        $product = $this->product($business, $main, stock: 10, salePrice: 100);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente Reporte',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
        ]);
        $this->sale($business, $main, $user, $product, total: 100, method: 'cash', customer: $customer);
        $this->sale($business, $other, $user, $product, total: 500, method: 'cash', customer: $customer);

        $this->actingAs($user)
            ->get(route('reports.sales-by-customer', ['customer_search' => 'Reporte']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.customer', 'Cliente Reporte')
                ->where('rows.data.0.total', 100)
                ->where('rows.total', 1));
    }

    public function test_sales_by_customer_searches_by_nit(): void
    {
        [$business, $user, $main] = $this->tenant();
        $product = $this->product($business, $main, stock: 10, salePrice: 100);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente NIT',
            'doc_type' => 'NIT',
            'doc_number' => '7654321',
        ]);
        $this->sale($business, $main, $user, $product, total: 120, method: 'cash', customer: $customer);

        $this->actingAs($user)
            ->get(route('reports.sales-by-customer', ['customer_search' => '7654']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.customer', 'Cliente NIT')
                ->where('rows.data.0.total', 120));
    }

    public function test_legacy_report_routes_redirect_to_new_reports(): void
    {
        [$business, $user] = $this->tenant();

        $this->actingAs($user)
            ->get(route('reports.sales'))
            ->assertRedirect(route('reports.sales-detailed'));

        $this->actingAs($user)
            ->get(route('reports.low-stock'))
            ->assertRedirect(route('reports.inventory'));

        $this->actingAs($user)
            ->get(route('reports.top-products'))
            ->assertRedirect(route('reports.products-sold-summary'));
    }

    public function test_report_permission_is_required(): void
    {
        [$business, $user] = $this->tenant(role: 'cashier');

        $this->actingAs($user)
            ->get(route('reports.profit'))
            ->assertForbidden();
    }

    public function test_user_with_report_permission_can_access_report(): void
    {
        [$business, $user] = $this->tenant();

        $this->actingAs($user)
            ->get(route('reports.profit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Reports/Generic'));
    }

    private function tenant(string $role = 'reports'): array
    {
        $business = Business::query()->create([
            'name' => 'Tenant reportes',
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => false,
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'reports',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $main = BranchInventory::defaultBranch($business->id);
        $other = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'is_super_admin' => false,
            'current_branch_id' => $main->id,
        ]);
        Permissions::assignRole($user, $role);

        return [$business, $user, $main, $other];
    }

    private function product(Business $business, Branch $branch, string $name = 'Producto reporte', int $stock = 10, float $salePrice = 100): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => 'SKU-'.uniqid(),
            'cost_price' => $salePrice / 2,
            'sale_price' => $salePrice,
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        ProductBranchStock::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id],
            ['stock' => $stock],
        );

        $priceType = PriceType::query()->updateOrCreate(
            ['business_id' => $business->id, 'name' => 'General'],
            ['is_default' => true, 'is_active' => true],
        );

        ProductPrice::query()->updateOrCreate(
            ['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $priceType->id],
            ['price' => $salePrice, 'is_active' => true],
        );

        return $product;
    }

    private function sale(
        Business $business,
        Branch $branch,
        User $user,
        Product $product,
        float $total,
        string $method = 'cash',
        float $unitCost = 50,
        ?float $profit = null,
        ?Customer $customer = null,
    ): Sale {
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'business_number' => Sale::query()->where('business_id', $business->id)->max('business_number') + 1,
            'branch_id' => $branch->id,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name ?? 'Consumidor Final',
            'customer_doc_type' => $customer?->doc_type ?? 'CF',
            'customer_doc_number' => $customer?->doc_number ?? 'CF',
            'subtotal_before_discount' => $total,
            'discount_amount' => 0,
            'total' => $total,
            'payment_method' => $method,
            'document_type' => 'receipt',
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $profit ??= $total - $unitCost;

        SaleItem::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost,
            'profit_amount' => $profit,
            'total' => $total,
        ]);

        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'method' => $method,
            'amount' => $total,
        ]);

        return $sale;
    }
}
