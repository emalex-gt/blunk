<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_admin_can_view_brands(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        Brand::create(['business_id' => $business->id, 'name' => 'Kia', 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('brands.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Brands/Index')
                ->where('brands.data.0.name', 'Kia')
            );
    }

    public function test_admin_can_create_brand(): void
    {
        [$business, $user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('brands.store'), [
                'name' => 'Bosch',
                'description' => 'Repuestos',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('brands', [
            'business_id' => $business->id,
            'name' => 'Bosch',
            'description' => 'Repuestos',
            'is_active' => true,
        ]);
    }

    public function test_duplicate_brand_name_within_business_is_blocked(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        Brand::create(['business_id' => $business->id, 'name' => 'Kia', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('brands.store'), ['name' => ' kia '])
            ->assertSessionHasErrors([
                'name' => 'Ya existe una marca con este nombre.',
            ]);
    }

    public function test_same_brand_name_in_another_business_is_allowed(): void
    {
        [$businessA] = $this->tenantUser('owner');
        [$businessB, $userB] = $this->tenantUser('owner');
        Brand::create(['business_id' => $businessA->id, 'name' => 'Kia', 'is_active' => true]);

        $this->actingAs($userB)
            ->post(route('brands.store'), ['name' => 'Kia'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('brands', [
            'business_id' => $businessB->id,
            'name' => 'Kia',
        ]);
    }

    public function test_admin_can_update_brand_and_deactivate_it(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $brand = Brand::create(['business_id' => $business->id, 'name' => 'Old', 'is_active' => true]);

        $this->actingAs($user)
            ->put(route('brands.update', $brand), [
                'name' => 'New',
                'description' => 'Actualizada',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('New', $brand->refresh()->name);

        $this->actingAs($user)
            ->delete(route('brands.destroy', $brand))
            ->assertSessionHasNoErrors();

        $this->assertFalse($brand->refresh()->is_active);
    }

    public function test_product_can_be_assigned_a_brand(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $brand = Brand::create(['business_id' => $business->id, 'name' => 'Kia', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('products.store'), [
                'name' => 'Bomba de inyección',
                'code' => '33100 4X700',
                'barcode' => null,
                'brand_id' => $brand->id,
                'cost_price' => 100,
                'sale_price' => 5500,
                'stock' => 0,
                'min_stock' => 0,
                'location' => null,
                'is_active' => true,
                'category_name' => null,
                'prices' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'brand_id' => $brand->id,
            'code' => '33100 4X700',
        ]);
    }

    public function test_product_page_receives_active_brands(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        Brand::create(['business_id' => $business->id, 'name' => 'Kia', 'is_active' => true]);
        Brand::create(['business_id' => $business->id, 'name' => 'Inactiva', 'is_active' => false]);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->where('brands.0.name', 'Kia')
                ->missing('brands.1')
            );
    }

    public function test_product_create_with_new_brand_name_creates_and_assigns_brand(): void
    {
        [$business, $user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'brand_name' => ' Bosch  Repuestos ',
            ]))
            ->assertSessionHasNoErrors();

        $brand = Brand::query()->where('business_id', $business->id)->where('name', 'Bosch Repuestos')->firstOrFail();
        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'brand_id' => $brand->id,
            'code' => 'PROD-CATALOG-001',
        ]);
    }

    public function test_product_create_with_existing_brand_name_reuses_brand(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $brand = Brand::create(['business_id' => $business->id, 'name' => 'Kia', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'brand_name' => 'kia',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Brand::query()->where('business_id', $business->id)->whereRaw('LOWER(name) = ?', ['kia'])->count());
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'brand_id' => $brand->id]);
    }

    public function test_product_create_with_inactive_brand_name_reactivates_brand(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $brand = Brand::create(['business_id' => $business->id, 'name' => 'Kia', 'is_active' => false]);

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'brand_name' => 'Kia',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($brand->refresh()->is_active);
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'brand_id' => $brand->id]);
    }

    public function test_product_edit_changing_brand_name_updates_brand_id(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $old = Brand::create(['business_id' => $business->id, 'name' => 'Old', 'is_active' => true]);
        $product = Product::create([
            'business_id' => $business->id,
            'brand_id' => $old->id,
            'name' => 'Producto editable',
            'code' => 'EDIT-BRAND-001',
            'cost_price' => 1,
            'sale_price' => 2,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('products.update', $product), $this->productPayload([
                'name' => 'Producto editable',
                'code' => 'EDIT-BRAND-001',
                'brand_name' => 'New Brand',
            ]))
            ->assertSessionHasNoErrors();

        $new = Brand::query()->where('business_id', $business->id)->where('name', 'New Brand')->firstOrFail();
        $this->assertSame($new->id, $product->refresh()->brand_id);
    }

    public function test_product_create_with_empty_brand_name_leaves_brand_null(): void
    {
        [$business, $user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'brand_name' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'code' => 'PROD-CATALOG-001',
            'brand_id' => null,
        ]);
    }

    public function test_product_create_with_new_location_name_creates_location_and_keeps_text_snapshot(): void
    {
        [$business, $user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'location_name' => ' Pasillo  3 ',
            ]))
            ->assertSessionHasNoErrors();

        $location = ProductLocation::query()->where('business_id', $business->id)->where('name', 'Pasillo 3')->firstOrFail();
        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'location_id' => $location->id,
            'location' => 'Pasillo 3',
        ]);
    }

    public function test_product_create_with_existing_location_name_reuses_location(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $location = ProductLocation::create(['business_id' => $business->id, 'name' => 'Bodega A', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'location_name' => 'bodega a',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ProductLocation::query()->where('business_id', $business->id)->whereRaw('LOWER(name) = ?', ['bodega a'])->count());
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'location_id' => $location->id]);
    }

    public function test_product_create_with_inactive_location_name_reactivates_location(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $location = ProductLocation::create(['business_id' => $business->id, 'name' => 'Bodega A', 'is_active' => false]);

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'location_name' => 'Bodega A',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($location->refresh()->is_active);
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'location_id' => $location->id]);
    }

    public function test_product_edit_changing_location_name_updates_location_id_and_text(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $old = ProductLocation::create(['business_id' => $business->id, 'name' => 'Old', 'is_active' => true]);
        $product = Product::create([
            'business_id' => $business->id,
            'location_id' => $old->id,
            'location' => 'Old',
            'name' => 'Producto editable ubicacion',
            'code' => 'EDIT-LOCATION-001',
            'cost_price' => 1,
            'sale_price' => 2,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('products.update', $product), $this->productPayload([
                'name' => 'Producto editable ubicacion',
                'code' => 'EDIT-LOCATION-001',
                'location_name' => 'Nueva ubicacion',
            ]))
            ->assertSessionHasNoErrors();

        $new = ProductLocation::query()->where('business_id', $business->id)->where('name', 'Nueva ubicacion')->firstOrFail();
        $this->assertSame($new->id, $product->refresh()->location_id);
        $this->assertSame('Nueva ubicacion', $product->location);
    }

    public function test_product_create_with_empty_location_name_leaves_location_null(): void
    {
        [$business, $user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload([
                'location_name' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'code' => 'PROD-CATALOG-001',
            'location_id' => null,
            'location' => null,
        ]);
    }

    public function test_product_page_receives_active_locations(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        ProductLocation::create(['business_id' => $business->id, 'name' => 'Bodega A', 'is_active' => true]);
        ProductLocation::create(['business_id' => $business->id, 'name' => 'Inactiva', 'is_active' => false]);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->where('locations.0.name', 'Bodega A')
                ->missing('locations.1')
            );
    }

    public function test_admin_can_manage_product_locations(): void
    {
        [$business, $user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->get(route('product-locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ProductLocations/Index'));

        $this->actingAs($user)
            ->post(route('product-locations.store'), [
                'name' => 'Bodega A',
                'description' => 'Principal',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $location = ProductLocation::query()->where('business_id', $business->id)->where('name', 'Bodega A')->firstOrFail();

        $this->actingAs($user)
            ->post(route('product-locations.store'), ['name' => ' bodega  a '])
            ->assertSessionHasErrors(['name' => 'Ya existe una ubicación con este nombre.']);

        $this->actingAs($user)
            ->put(route('product-locations.update', $location), [
                'name' => 'Bodega B',
                'description' => 'Secundaria',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Bodega B', $location->refresh()->name);

        $this->actingAs($user)
            ->delete(route('product-locations.destroy', $location))
            ->assertSessionHasNoErrors();

        $this->assertFalse($location->refresh()->is_active);
    }

    public function test_unauthorized_user_cannot_manage_brands_and_guest_cannot_access(): void
    {
        [, $cashier] = $this->tenantUser('cashier');

        $this->get(route('brands.index'))->assertRedirect(route('login'));

        $this->actingAs($cashier)
            ->get(route('brands.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_manage_product_locations(): void
    {
        [, $cashier] = $this->tenantUser('cashier');

        $this->get(route('product-locations.index'))->assertRedirect(route('login'));

        $this->actingAs($cashier)
            ->get(route('product-locations.index'))
            ->assertForbidden();
    }

    private function productPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Producto catalogo',
            'code' => 'PROD-CATALOG-001',
            'barcode' => null,
            'brand_name' => '',
            'cost_price' => 100,
            'sale_price' => 150,
            'stock' => 0,
            'min_stock' => 0,
            'location' => null,
            'location_name' => '',
            'is_active' => true,
            'category_name' => null,
            'prices' => [],
        ], $overrides);
    }

    private function tenantUser(string $role): array
    {
        $business = Business::create([
            'name' => 'Brand Tenant '.uniqid(),
            'slug' => 'brand-tenant-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::create([
            'business_id' => $business->id,
            'use_product_images' => false,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_manual_price' => false,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => false,
            'allow_negative_stock' => false,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        TenantModule::create([
            'business_id' => $business->id,
            'module' => 'inventory',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        Permissions::assignRole($user, $role);

        return [$business, $user];
    }
}
