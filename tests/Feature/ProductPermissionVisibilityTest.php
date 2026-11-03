<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\TenantModule;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductPermissionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_view_only_user_receives_no_financial_product_data_and_cannot_write(): void
    {
        [$business, $user, $product] = $this->fixture([Permissions::PRODUCTS_VIEW]);

        $this->as($user, $business)->get(route('products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->missing('products.data.0.cost_price')
            ->missing('products.data.0.supplier_cost_history'));
        $this->as($user, $business)->post(route('products.store'), $this->payload())->assertForbidden();
        $this->as($user, $business)->put(route('products.update', $product), $this->payload())->assertForbidden();
    }

    public function test_create_and_update_permissions_are_independent(): void
    {
        [$business, $creator, $product] = $this->fixture([Permissions::PRODUCTS_VIEW, Permissions::PRODUCTS_CREATE]);
        $this->as($creator, $business)->post(route('products.store'), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'name' => 'Producto nuevo']);
        $this->as($creator, $business)->put(route('products.update', $product), [...$this->payload(), 'name' => 'No permitido'])->assertForbidden();
        $this->assertSame('Producto', $product->fresh()->name);

        $editor = User::factory()->create(['business_id' => $business->id, 'is_super_admin' => false, 'is_active' => true]);
        Permissions::assignDirectPermissions($editor, [Permissions::PRODUCTS_VIEW, Permissions::PRODUCTS_UPDATE]);
        $this->as($editor, $business)->post(route('products.store'), $this->payload())->assertForbidden();
        $this->as($editor, $business)->put(route('products.update', $product), [...$this->payload(), 'name' => 'Actualizado'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Actualizado', $product->fresh()->name);
    }

    public function test_financial_permissions_expose_only_their_authorized_data(): void
    {
        [$business, $profitUser] = $this->fixture([Permissions::PRODUCTS_VIEW, Permissions::REPORTS_PROFIT_VIEW]);
        $this->as($profitUser, $business)->get(route('products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->has('products.data.0.cost_price')
            ->missing('products.data.0.supplier_cost_history'));

        $historyUser = User::factory()->create(['business_id' => $business->id, 'is_super_admin' => false, 'is_active' => true]);
        Permissions::assignDirectPermissions($historyUser, [Permissions::PRODUCTS_VIEW, Permissions::REPORTS_PURCHASE_COST_HISTORY_VIEW]);
        $this->as($historyUser, $business)->get(route('products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->missing('products.data.0.cost_price')
            ->has('products.data.0.supplier_cost_history'));
    }

    public function test_product_index_rechecks_inventory_module_for_each_http_request(): void
    {
        [$business, $user] = $this->fixture([Permissions::PRODUCTS_VIEW]);
        $module = TenantModule::query()
            ->where('business_id', $business->id)
            ->where('module', 'inventory')
            ->firstOrFail();

        $this->as($user, $business)->get(route('products.index'))->assertOk();

        $module->update(['is_enabled' => false, 'disabled_at' => now()]);
        $this->as($user, $business)->get(route('products.index'))->assertForbidden();

        $module->update(['is_enabled' => true, 'disabled_at' => null, 'enabled_at' => now()]);
        $this->as($user, $business)->get(route('products.index'))->assertOk();
    }

    public function test_product_updates_are_tenant_scoped_and_super_admin_retains_access(): void
    {
        [$business, $user] = $this->fixture([Permissions::PRODUCTS_VIEW, Permissions::PRODUCTS_UPDATE]);
        [$otherBusiness, , $otherProduct] = $this->fixture([Permissions::PRODUCTS_VIEW]);
        $this->as($user, $business)->put(route('products.update', $otherProduct), $this->payload())->assertForbidden();

        $superAdmin = User::factory()->create(['business_id' => $otherBusiness->id, 'is_super_admin' => true, 'is_active' => true]);
        $this->as($superAdmin, $otherBusiness)->get(route('products.index'))->assertOk();
    }

    private function fixture(array $permissions): array
    {
        $business = Business::query()->create(['name' => uniqid('Productos '), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'inventory', 'is_enabled' => true, 'enabled_at' => now()]);
        $branch = BranchInventory::defaultBranch($business->id);
        $user = User::factory()->create(['business_id' => $business->id, 'is_super_admin' => false, 'is_active' => true]);
        Permissions::assignDirectPermissions($user, $permissions);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto', 'code' => uniqid('SKU'), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 0, 'min_stock' => 0, 'is_active' => true]);
        BranchInventory::adjust($product, $branch->id, 2);
        return [$business, $user, $product];
    }

    private function as(User $user, Business $business): static
    {
        return $this->actingAs($user)->withSession(['active_business_id' => $business->id, 'current_business_id' => $business->id]);
    }

    private function payload(): array
    {
        return ['name' => 'Producto nuevo', 'code' => uniqid('SKU'), 'barcode' => null, 'cost_price' => '10.00000', 'sale_price' => '20.00', 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
    }
}
