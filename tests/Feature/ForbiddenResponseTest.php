<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Business;
use App\Models\TenantModule;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ForbiddenResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->withoutVite(); Permissions::syncDefaults(); }

    public function test_forbidden_web_and_inertia_responses_are_safe(): void
    {
        [$business, $user] = $this->fixture([]);
        $this->as($user, $business)->get(route('brands.index'))->assertStatus(403)->assertInertia(fn (Assert $page) => $page->component('Errors/Forbidden')->where('message', 'No tienes permiso para acceder a esta sección.'));
        $this->as($user, $business)->get(route('brands.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertStatus(403)->assertHeader('X-Inertia', 'true');
    }

    public function test_forbidden_json_and_post_do_not_leak_or_write(): void
    {
        [$business, $user] = $this->fixture([]);
        $this->as($user, $business)->getJson(route('brands.index'))->assertStatus(403)->assertJson(['message' => 'No tienes permiso para realizar esta acción.']);
        $this->as($user, $business)->post(route('brands.store'), ['name' => 'Bloqueada'])->assertStatus(403);
        $this->assertDatabaseMissing('brands', ['business_id' => $business->id, 'name' => 'Bloqueada']);
    }

    public function test_authorized_user_and_login_redirect_remain_unchanged(): void
    {
        [$business, $user] = $this->fixture([Permissions::BRANDS_VIEW]);
        $this->as($user, $business)->get(route('brands.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Brands/Index'));
        $this->get(route('brands.index'))->assertRedirect(route('login'));
    }

    private function fixture(array $permissions): array
    {
        $business = Business::query()->create(['name' => 'Forbidden', 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'inventory', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($user, $permissions);
        return [$business, $user];
    }

    private function as(User $user, Business $business): static
    {
        return $this->actingAs($user)->withSession(['current_business_id' => $business->id, 'active_business_id' => $business->id]);
    }
}
