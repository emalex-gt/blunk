<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertStatus(200)
            ->assertDontSee('name="csrf-token"', false);
    }

    public function test_guest_can_view_login_page(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_guest_login_page_returns_200_not_403(): void
    {
        $this->get(route('login'))->assertStatus(200);
    }

    public function test_guest_inertia_props_do_not_require_business(): void
    {
        $this->withoutVite();

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('auth.user', null)
                ->where('current_business_id', null)
                ->where('current_business', null)
                ->where('business', null)
                ->where('tenant_settings', null)
                ->where('fel_settings', null)
                ->where('subscription_status', null)
                ->where('enabled_modules', [])
                ->where('branches_enabled', false)
                ->where('branch_can_switch', false)
                ->where('active_branch', null)
                ->where('branches', [])
                ->where('use_product_images', true)
            );
    }

    public function test_after_logout_stale_business_session_does_not_forbid_login(): void
    {
        $this->withSession([
            'active_business_id' => 999,
            'current_business_id' => 999,
            'business_id' => 999,
            'tenant_id' => 999,
        ])->get(route('login'))->assertOk();
    }

    public function test_after_logout_stale_branch_session_does_not_forbid_login(): void
    {
        $this->withSession([
            'active_business_id' => 1,
            'active_branch_id' => 1,
            'selected_branch_id' => 1,
            'route_work_day_id' => 1,
            'route_zone_id' => 1,
        ])->get(route('login'))->assertOk();
    }

    public function test_user_can_login_logout_and_view_login_again(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->withSession([
            'active_business_id' => 123,
            'active_branch_id' => 456,
            'route_work_day_id' => 789,
        ])->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('login'))->assertOk();
    }

    public function test_protected_dashboard_still_requires_auth(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $this->withoutVite();
        [$user] = $this->tenantUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('stats')
            );
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_logout_route_requires_post(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/logout')
            ->assertMethodNotAllowed();

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_logout_and_login_again(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_another_user_can_login_immediately_after_logout(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->post('/login', [
            'email' => $userA->email,
            'password' => 'password',
        ]);

        $this->post('/logout')->assertRedirect(route('login'));

        $this->post('/login', [
            'email' => $userB->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($userB);
    }

    private function tenantUser(): array
    {
        Permissions::syncDefaults();

        $business = Business::create([
            'name' => 'Auth Tenant',
            'slug' => 'auth-tenant-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::create([
            'business_id' => $business->id,
            'use_product_images' => true,
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
            'module' => 'pos',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        Permissions::assignRole($user, 'cashier');

        return [$user, $business];
    }
}
