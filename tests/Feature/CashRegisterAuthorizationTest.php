<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashRegisterAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permissions::syncDefaults();
    }

    public function test_custom_cashier_role_can_open_cash_register_index_with_the_view_permission(): void
    {
        [, $user] = $this->cashierWithPermissions([
            Permissions::CASH_REGISTER_VIEW,
            Permissions::CASH_REGISTER_OPEN,
            Permissions::CASH_REGISTER_EXPENSES,
            Permissions::CASH_REGISTER_CLOSE,
        ]);

        $this->actingAs($user)
            ->get(route('cash-register.index'))
            ->assertOk();
    }

    public function test_cash_register_view_permission_does_not_grant_mutating_cash_actions(): void
    {
        [, $user] = $this->cashierWithPermissions([Permissions::CASH_REGISTER_VIEW]);

        $this->actingAs($user)
            ->get(route('cash-register.index'))
            ->assertOk();

        $this->actingAs($user)->post(route('cash-register.open'), [])->assertForbidden();
        $this->actingAs($user)->post(route('cash-register.expenses.store'), [])->assertForbidden();
        $this->actingAs($user)->post(route('cash-register.close'), [])->assertForbidden();
    }

    public function test_cash_register_page_source_hides_actions_without_their_permissions(): void
    {
        $source = file_get_contents(resource_path('js/Pages/CashRegister/Index.tsx'));

        $this->assertStringContainsString("const canOpenCashRegister = can('cash_register.open');", $source);
        $this->assertStringContainsString("const canRegisterExpenses = can('cash_register.expenses');", $source);
        $this->assertStringContainsString("const canCloseCashRegister = can('cash_register.close');", $source);
        $this->assertStringContainsString('{openingModalOpen && canOpenCashRegister && (', $source);
        $this->assertStringContainsString('{expenseModalOpen && canRegisterExpenses && (', $source);
        $this->assertStringContainsString('{closeModalOpen && openSession && canCloseCashRegister && (', $source);
    }

    private function cashierWithPermissions(array $permissionKeys): array
    {
        $business = Business::query()->create([
            'name' => 'Caja RBAC '.uniqid(),
            'slug' => 'cash-rbac-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);
        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'cash_register',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $role = Role::query()->create([
            'business_id' => $business->id,
            'key' => 'cajero-admin',
            'name' => 'Cajero administrador',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cajero-admin',
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        $user->roles()->sync([$role->id]);

        return [$business, $user];
    }
}
