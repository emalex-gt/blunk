<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ListFilterExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_purchases_filter_by_date_and_payment_method(): void
    {
        [$business, $user, $branch] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);
        $this->purchase($business, $branch, $user, $supplier, 100, 'cash', now()->subMonth());
        $this->purchase($business, $branch, $user, $supplier, 200, 'card', now());

        $this->actingAs($user)
            ->get(route('purchases.index', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'payment_method' => 'card',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Index')
                ->where('purchases.total', 1)
                ->where('purchases.data.0.total', '200.00'));
    }

    public function test_purchases_export_requires_permission_and_is_branch_scoped(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);
        $this->purchase($business, $branch, $user, $supplier, 100, 'cash', now());
        $this->purchase($business, $otherBranch, $user, $supplier, 900, 'cash', now());

        $this->actingAs($user)
            ->get(route('purchases.export', ['format' => 'excel']))
            ->assertOk();

        $cashier = $this->user($business, $branch, 'cashier');

        $this->actingAs($cashier)
            ->get(route('purchases.export', ['format' => 'excel']))
            ->assertForbidden();
    }

    public function test_transfers_filter_by_origin_destination_and_product(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant('stock_manager', ['branches']);
        $product = $this->product($business, 'Producto filtro');
        $this->transfer($business, $branch, $otherBranch, $user, $product, 2);
        $otherProduct = $this->product($business, 'Producto oculto');
        $this->transfer($business, $otherBranch, $branch, $user, $otherProduct, 5);

        $this->actingAs($user)
            ->get(route('inventory.transfers.index', [
                'origin_branch_id' => $branch->id,
                'destination_branch_id' => $otherBranch->id,
                'product_search' => 'filtro',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Transfers/Index')
                ->where('transfers.total', 1));
    }

    public function test_transfers_export_requires_permission_and_is_branch_scoped(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant('stock_manager', ['branches']);
        $product = $this->product($business, 'Producto traslado');
        $this->transfer($business, $branch, $otherBranch, $user, $product, 2);

        $this->actingAs($user)
            ->get(route('inventory.transfers.export', ['format' => 'pdf']))
            ->assertOk();

        $cashier = $this->user($business, $branch, 'cashier');

        $this->actingAs($cashier)
            ->get(route('inventory.transfers.export', ['format' => 'pdf']))
            ->assertForbidden();
    }

    private function tenant(string $role, array $modules): array
    {
        $business = Business::query()->create([
            'name' => 'Tenant export',
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
            'allow_receipts' => true,
            'allow_invoices' => false,
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
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $user = $this->user($business, $branch, $role);

        return [$business, $user, $branch, $otherBranch];
    }

    private function user(Business $business, Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'current_branch_id' => $branch->id,
        ]);
        Permissions::assignRole($user, $role);

        return $user;
    }

    private function product(Business $business, string $name): Product
    {
        return Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => uniqid('P-'),
            'cost_price' => 10,
            'sale_price' => 20,
            'stock' => 10,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function purchase(Business $business, Branch $branch, User $user, Supplier $supplier, float $total, string $method, $createdAt): Purchase
    {
        $purchase = Purchase::query()->create([
            'business_id' => $business->id,
            'business_number' => Purchase::query()->where('business_id', $business->id)->max('business_number') + 1,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total' => $total,
            'payment_method' => $method,
            'paid_from_cash' => $method === 'cash',
            'created_by' => $user->id,
        ]);
        $purchase->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $purchase;
    }

    private function transfer(Business $business, Branch $from, Branch $to, User $user, Product $product, int $quantity): InventoryTransfer
    {
        $transfer = InventoryTransfer::query()->create([
            'business_id' => $business->id,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);
        $transfer->lines()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $transfer;
    }
}
