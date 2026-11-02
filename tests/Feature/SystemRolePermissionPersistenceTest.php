<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemRolePermissionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permissions::syncDefaults();
    }

    public function test_system_role_permission_changes_survive_default_sync_and_seeding(): void
    {
        $business = $this->business();
        $superAdmin = User::factory()->create(['is_super_admin' => true, 'role' => 'super_admin']);
        $cashier = Role::query()->whereNull('business_id')->where('key', 'cashier')->firstOrFail();
        $permission = Permission::query()->where('key', Permissions::REPORTS_PROFIT_VIEW)->firstOrFail();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_super_admin' => false,
        ]);
        Permissions::assignRole($user, 'cashier');
        Permissions::assignDirectPermissions($user, [Permissions::USERS_VIEW]);

        $this->actingAs($superAdmin)
            ->put(route('super-admin.security.roles.update', $cashier), $this->rolePayload($cashier, [
                ...$cashier->permissions()->pluck('key')->all(),
                $permission->key,
            ]))
            ->assertRedirect();

        $this->assertTrue(Permissions::userHas($user->fresh(), $permission->key));

        Permissions::syncDefaults();
        $this->seed(PermissionSeeder::class);

        $this->assertTrue($cashier->fresh()->permissions()->whereKey($permission->id)->exists());
        $this->assertTrue(Permissions::userHas($user->fresh(), $permission->key));

        $this->actingAs($superAdmin)
            ->put(route('super-admin.security.roles.update', $cashier), $this->rolePayload(
                $cashier->fresh(),
                array_values(array_diff(
                    $cashier->fresh()->permissions()->pluck('key')->all(),
                    [$permission->key],
                )),
            ))
            ->assertRedirect();

        Permissions::syncDefaults();

        $this->assertFalse($cashier->fresh()->permissions()->whereKey($permission->id)->exists());
        $this->assertFalse(Permissions::userHas($user->fresh(), $permission->key));
        $this->assertTrue(Permissions::userHas($user->fresh(), Permissions::USERS_VIEW));
    }

    public function test_backfill_preserves_existing_custom_role_assignments(): void
    {
        $business = $this->business();
        $customRole = Role::query()->create([
            'business_id' => $business->id,
            'key' => 'custom_operations',
            'name' => 'Operaciones personalizadas',
            'is_system' => false,
            'is_active' => true,
        ]);
        $customRole->permissions()->sync(
            Permission::query()->where('key', Permissions::PRODUCTS_VIEW)->pluck('id')->all(),
        );
        $customizedUser = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_super_admin' => false,
        ]);
        $customizedUser->roles()->sync([$customRole->id]);
        $unassignedUser = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_super_admin' => false,
        ]);

        Permissions::backfillUserRoles();

        $this->assertTrue($customizedUser->fresh()->roles()->whereKey($customRole->id)->exists());
        $this->assertFalse($customizedUser->fresh()->roles()->where('key', 'cashier')->exists());
        $this->assertTrue($unassignedUser->fresh()->roles()->where('key', 'cashier')->exists());
    }

    public function test_super_admin_role_permissions_cannot_be_changed_over_http(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true, 'role' => 'super_admin']);
        $role = Role::query()->whereNull('business_id')->where('key', 'super_admin')->firstOrFail();
        $permissionIds = $role->permissions()->pluck('id')->sort()->values()->all();

        $this->actingAs($superAdmin)
            ->put(route('super-admin.security.roles.update', $role), $this->rolePayload($role, []))
            ->assertStatus(422);

        $this->assertSame($permissionIds, $role->fresh()->permissions()->pluck('id')->sort()->values()->all());
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Tenant roles '.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
    }

    private function rolePayload(Role $role, array $permissions): array
    {
        return [
            'scope' => $role->business_id ? 'tenant' : 'global',
            'business_id' => $role->business_id,
            'key' => $role->key,
            'name' => $role->name,
            'is_active' => $role->is_active,
            'permissions' => $permissions,
        ];
    }
}
