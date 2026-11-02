<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Business;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Category;
use App\Models\Brand;
use App\Models\ProductLocation;
use App\Models\PriceType;
use App\Support\Permissions;

class CatalogPermissionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); Permissions::syncDefaults();
    }

    public function test_category_permissions_are_independent_over_http(): void
    {
        [$business, $viewer] = $this->fixture([Permissions::PRODUCTS_VIEW]);
        $category = Category::query()->create(['business_id' => $business->id, 'name' => 'Inicial']);
        $this->as($viewer, $business)->get(route('categories.index'))->assertOk();
        $this->as($viewer, $business)->post(route('categories.store'), ['name' => 'Bloqueada'])->assertForbidden();
        $this->as($viewer, $business)->put(route('categories.update', $category), ['name' => 'Bloqueada'])->assertForbidden();
        $this->assertSame('Inicial', $category->fresh()->name);

        [, $creator] = $this->fixture([Permissions::PRODUCTS_VIEW, Permissions::PRODUCTS_CREATE]);
        $creator->update(['business_id' => $business->id]); Permissions::assignDirectPermissions($creator, [Permissions::PRODUCTS_VIEW, Permissions::PRODUCTS_CREATE]);
        $this->as($creator, $business)->post(route('categories.store'), ['name' => 'Nueva'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['business_id' => $business->id, 'name' => 'Nueva']);
    }

    public function test_brand_permissions_guard_each_mutation(): void
    {
        [$business, $viewer] = $this->fixture([Permissions::BRANDS_VIEW]);
        $brand = Brand::query()->create(['business_id' => $business->id, 'name' => 'Marca', 'is_active' => true]);
        $this->as($viewer, $business)->get(route('brands.index'))->assertOk();
        $this->as($viewer, $business)->post(route('brands.store'), ['name' => 'Bloqueada'])->assertForbidden();
        $this->as($viewer, $business)->put(route('brands.update', $brand), ['name' => 'Bloqueada'])->assertForbidden();
        $this->as($viewer, $business)->delete(route('brands.destroy', $brand))->assertForbidden();
        $this->assertSame('Marca', $brand->fresh()->name);

        $writer = $this->user($business, [Permissions::BRANDS_VIEW, Permissions::BRANDS_CREATE, Permissions::BRANDS_EDIT, Permissions::BRANDS_DELETE]);
        $this->as($writer, $business)->post(route('brands.store'), ['name' => 'Nueva'])->assertRedirect();
        $this->as($writer, $business)->put(route('brands.update', $brand), ['name' => 'Editada'])->assertRedirect();
        $this->as($writer, $business)->delete(route('brands.destroy', $brand))->assertRedirect();
        $this->assertFalse($brand->fresh()->is_active);
    }

    public function test_location_permissions_guard_each_mutation(): void
    {
        [$business, $viewer] = $this->fixture([Permissions::PRODUCT_LOCATIONS_VIEW]);
        $location = ProductLocation::query()->create(['business_id' => $business->id, 'name' => 'Bodega', 'is_active' => true]);
        $this->as($viewer, $business)->get(route('product-locations.index'))->assertOk();
        $this->as($viewer, $business)->post(route('product-locations.store'), ['name' => 'Bloqueada'])->assertForbidden();
        $this->as($viewer, $business)->put(route('product-locations.update', $location), ['name' => 'Bloqueada'])->assertForbidden();
        $this->as($viewer, $business)->delete(route('product-locations.destroy', $location))->assertForbidden();
        $writer = $this->user($business, [Permissions::PRODUCT_LOCATIONS_VIEW, Permissions::PRODUCT_LOCATIONS_CREATE, Permissions::PRODUCT_LOCATIONS_EDIT, Permissions::PRODUCT_LOCATIONS_DELETE]);
        $this->as($writer, $business)->post(route('product-locations.store'), ['name' => 'Nueva'])->assertRedirect();
        $this->as($writer, $business)->put(route('product-locations.update', $location), ['name' => 'Editada'])->assertRedirect();
        $this->as($writer, $business)->delete(route('product-locations.destroy', $location))->assertRedirect();
        $this->assertFalse($location->fresh()->is_active);
    }

    public function test_price_list_view_is_read_only_without_manage_permission(): void
    {
        [$business, $viewer] = $this->fixture([Permissions::PRICE_LISTS_VIEW]);
        $priceType = PriceType::query()->create(['business_id' => $business->id, 'name' => 'General', 'is_active' => true, 'is_default' => true]);
        $this->as($viewer, $business)->get(route('price-lists.index'))->assertOk();
        $this->as($viewer, $business)->post(route('price-lists.store'), ['name' => 'Bloqueada'])->assertForbidden();
        $this->as($viewer, $business)->patch(route('price-lists.update', $priceType), ['name' => 'Bloqueada'])->assertForbidden();
        $this->as($viewer, $business)->delete(route('price-lists.destroy', $priceType))->assertForbidden();
        $this->assertSame('General', $priceType->fresh()->name);
    }

    private function fixture(array $permissions): array
    {
        $business = Business::query()->create(['name' => uniqid('Cat '), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'inventory', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'is_active' => true]); Permissions::assignDirectPermissions($user, $permissions);
        return [$business, $user];
    }

    private function user(Business $business, array $permissions): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($user, $permissions);
        return $user;
    }

    private function as(User $user, Business $business): static
    {
        return $this->actingAs($user)->withSession(['current_business_id' => $business->id, 'active_business_id' => $business->id]);
    }
}
