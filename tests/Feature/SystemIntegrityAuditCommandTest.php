<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerCreditAccount;
use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemIntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_detects_stock_discrepancy_without_modifying_stock(): void
    {
        [$business, $branch] = $this->business();
        $product = $this->product($business);
        $this->stock($business, $branch, $product, 5);
        $this->movement($business, $branch, $product, 'entry', 3);

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'stock', '--dry-run' => true])
            ->expectsOutputToContain('stock: 1 críticos')
            ->assertExitCode(1);

        $this->assertSame(5.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
    }

    public function test_detects_sale_total_mismatch(): void
    {
        [$business, $branch] = $this->business();
        $sale = $this->sale($business, $branch, 100);
        $this->saleItem($business, $sale, $this->product($business), 1, 80);

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'sales'])
            ->expectsOutputToContain('sales: 1 críticos')
            ->assertExitCode(1);
    }

    public function test_detects_cash_sale_without_cash_movement(): void
    {
        [$business, $branch] = $this->business();
        $sale = $this->sale($business, $branch, 10, 'cash');
        $this->saleItem($business, $sale, $this->product($business), 1, 10);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 10]);

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'sales'])
            ->expectsOutputToContain('sales: 1 críticos')
            ->assertExitCode(1);
    }

    public function test_detects_cancelled_sale_with_active_cash(): void
    {
        [$business, $branch] = $this->business();
        $sale = $this->sale($business, $branch, 10, 'cash', 'cancelled');
        $this->saleItem($business, $sale, $this->product($business), 1, 10);
        SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 10]);
        $session = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
        DB::table('cash_movements')->insert([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'cash_register_session_id' => $session->id,
            'type' => 'sale_cash',
            'amount' => 10,
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'sales'])
            ->expectsOutputToContain('sales: 1 críticos')
            ->assertExitCode(1);
    }

    public function test_detects_accounts_receivable_balance_mismatch(): void
    {
        [$business, $branch] = $this->business();
        $customer = $this->customer($business);
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'current_balance' => 20,
        ]);

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'ar'])
            ->expectsOutputToContain('ar: 1 críticos')
            ->assertExitCode(1);
    }

    public function test_detects_suspicious_duplicate_purchases(): void
    {
        [$business, $branch] = $this->business();
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor']);
        $product = $this->product($business);
        foreach ([1, 2] as $number) {
            $purchase = Purchase::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => 'FAC-1',
                'payment_method' => 'card',
                'status' => 'completed',
                'total' => 10,
            ]);
            PurchaseItem::query()->create(['business_id' => $business->id, 'purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_cost' => 10, 'previous_cost' => 10, 'new_average_cost' => 10, 'total' => 10]);
        }

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'purchases'])
            ->expectsOutputToContain('purchases: 1 críticos')
            ->assertExitCode(1);
    }

    public function test_detects_suspicious_duplicate_transfers(): void
    {
        [$business, $from] = $this->business();
        $to = Branch::query()->create(['business_id' => $business->id, 'name' => 'Destino', 'is_active' => true]);
        $product = $this->product($business);
        foreach ([1, 2] as $number) {
            $transfer = InventoryTransfer::query()->create(['business_id' => $business->id, 'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'status' => 'completed']);
            InventoryTransferLine::query()->create(['business_id' => $business->id, 'inventory_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 1]);
        }

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'transfers'])
            ->expectsOutputToContain('transfers:')
            ->assertExitCode(1);
    }

    public function test_detects_inconsistent_credit_reservation(): void
    {
        [$business, $branch] = $this->business();
        $customer = $this->customer($business);
        $product = $this->product($business);
        $this->stock($business, $branch, $product, 1);
        $receipt = CreditReceipt::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_number' => $customer->doc_number,
            'receipt_number' => 1,
            'status' => 'pending',
            'subtotal' => 20,
            'total' => 20,
            'pending_total' => 20,
        ]);
        CreditReceiptLine::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'credit_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'qty_reserved' => 2,
            'qty_pending' => 1,
            'unit_price' => 10,
            'line_total' => 20,
            'pending_total' => 10,
            'status' => 'pending',
        ]);

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'credit-reservations'])
            ->expectsOutputToContain('credit-reservations: 2 críticos')
            ->assertExitCode(1);
    }

    public function test_writes_csv_reports_and_keeps_read_only_behavior(): void
    {
        [$business] = $this->business();

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'sales', '--dry-run' => true, '--report' => true])
            ->expectsOutputToContain('reporte: ')
            ->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('system-integrity-audits');
        $this->assertTrue(collect($files)->contains(fn (string $path) => str_ends_with($path, 'summary.csv')));
        $this->assertTrue(collect($files)->contains(fn (string $path) => str_ends_with($path, 'stock_discrepancies.csv')));
        $this->assertTrue(collect($files)->contains(fn (string $path) => str_ends_with($path, 'credit_reservation_issues.csv')));
    }

    public function test_business_scope_does_not_mix_other_tenant_issues(): void
    {
        [$business] = $this->business('Principal');
        [$otherBusiness, $otherBranch] = $this->business('Otro negocio');
        $otherProduct = $this->product($otherBusiness);
        $this->stock($otherBusiness, $otherBranch, $otherProduct, 5);
        $this->movement($otherBusiness, $otherBranch, $otherProduct, 'entry', 1);

        $this->artisan('system:audit-integrity', ['--business' => $business->id, '--section' => 'stock'])
            ->expectsOutputToContain('stock: 0 críticos')
            ->assertExitCode(0);
    }

    private function business(string $name = 'Negocio'): array
    {
        $business = Business::query()->create(['name' => $name, 'country' => 'GT', 'currency' => 'GTQ', 'is_active' => true]);

        return [$business, BranchInventory::defaultBranch($business->id)];
    }

    private function product(Business $business): Product
    {
        return Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto '.uniqid(),
            'code' => 'CODE-'.uniqid(),
            'barcode' => 'BAR-'.uniqid(),
            'cost_price' => 1,
            'sale_price' => 10,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function stock(Business $business, Branch $branch, Product $product, float $stock): void
    {
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]);
    }

    private function movement(Business $business, Branch $branch, Product $product, string $type, float $quantity): void
    {
        StockMovement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'type' => $type, 'quantity' => $quantity, 'previous_stock' => 0, 'new_stock' => $quantity]);
    }

    private function customer(Business $business): Customer
    {
        return Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente', 'doc_type' => 'NIT', 'doc_number' => '1234567']);
    }

    private function sale(Business $business, Branch $branch, float $total, string $paymentMethod = 'card', string $status = 'completed'): Sale
    {
        return Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => random_int(1000, 9999), 'total' => $total, 'payment_method' => $paymentMethod, 'payment_status' => 'paid', 'status' => $status]);
    }

    private function saleItem(Business $business, Sale $sale, Product $product, int $quantity, float $total): void
    {
        SaleItem::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => $total / $quantity, 'unit_cost' => 1, 'total' => $total, 'discount_amount' => 0]);
    }
}
