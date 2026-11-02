<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Business;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Category;
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

    private function fixture(array $permissions): array
    {
        $business = Business::query()->create(['name' => uniqid('Cat '), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'inventory', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'is_active' => true]); Permissions::assignDirectPermissions($user, $permissions);
        return [$business, $user];
    }

    private function as(User $user, Business $business): static
    {
        return $this->actingAs($user)->withSession(['current_business_id' => $business->id, 'active_business_id' => $business->id]);
    }
}
