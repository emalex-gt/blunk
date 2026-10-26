<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\InventoryTransfer;
use App\Models\Purchase;
use App\Models\RouteCashSettlement;
use App\Models\Sale;
use App\Models\User;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Services\BranchAccessService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_access_allows_only_assigned_active_branches(): void
    {
        $business = $this->business('A');
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true]);
        $a3 = $this->branch($business, 'A3');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id]);

        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a2->id]);

        $access = app(BranchAccessService::class);

        $this->assertTrue($access->canAccessBranch($user, $a1));
        $this->assertTrue($access->canAccessBranch($user, $a2));
        $this->assertFalse($access->canAccessBranch($user, $a3));
        $this->assertSame([$a1->id, $a2->id], $access->authorizedBranches($user, $business)->pluck('id')->all());
    }

    public function test_explicit_all_branch_access_includes_all_active_tenant_branches_but_not_foreign_branch(): void
    {
        $business = $this->business('A');
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $foreign = $this->branch($this->business('B'), 'B1');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id, 'all_branches_access' => true]);

        $access = app(BranchAccessService::class);

        $this->assertTrue($access->canAccessBranch($user, $a1));
        $this->assertTrue($access->canAccessBranch($user, $a2));
        $this->assertFalse($access->canAccessBranch($user, $foreign));
    }

    public function test_platform_superadmin_keeps_explicit_cross_tenant_branch_resolution_without_pivots(): void
    {
        $businessA = $this->business('A');
        $businessB = $this->business('B');
        $a1 = $this->branch($businessA, 'A1');
        $b1 = $this->branch($businessB, 'B1');
        $superadmin = User::factory()->create([
            'business_id' => null,
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $access = app(BranchAccessService::class);

        $this->assertTrue($access->canAccessBranch($superadmin, $a1));
        $this->assertTrue($access->canAccessBranch($superadmin, $b1));
        $this->assertSame([$b1->id], $access->authorizedBranches($superadmin, $businessB)->pluck('id')->all());
        $this->assertDatabaseMissing('user_branch_accesses', ['user_id' => $superadmin->id]);
    }

    public function test_reducing_all_access_to_selected_access_replaces_assignments_atomically(): void
    {
        $business = $this->business('A');
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $a3 = $this->branch($business, 'A3');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id, 'all_branches_access' => true]);

        app(BranchAccessService::class)->replaceAccess($user, false, [$a1->id, $a2->id]);

        $user->refresh();
        $this->assertFalse($user->all_branches_access);
        $this->assertSame([$a1->id, $a2->id], $user->branchAccesses()->orderBy('branch_id')->pluck('branch_id')->all());
        $this->assertFalse(app(BranchAccessService::class)->canAccessBranch($user, $a3));
    }

    public function test_stale_active_branch_recovers_to_an_authorized_branch(): void
    {
        $business = $this->business('A');
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a2->id]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);
        $this->actingAs($user)->withSession(['active_branch_id' => $a2->id]);

        $branch = app(BranchAccessService::class)->resolveActiveBranch($user, $business->id);

        $this->assertSame($a1->id, $branch->id);
        $this->assertNull(session('active_branch_id'));
        $this->assertSame($a1->id, $user->fresh()->current_branch_id);
    }

    public function test_active_branch_never_uses_an_unassigned_current_branch(): void
    {
        $business = $this->business('A');
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a2->id]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);
        $this->actingAs($user);

        $this->assertSame($a1->id, BranchInventory::activeBranch($business->id)->id);
    }

    public function test_database_rejects_a_branch_assignment_that_becomes_cross_tenant_after_a_parent_change(): void
    {
        $businessA = $this->business('A');
        $businessB = $this->business('B');
        $a1 = $this->branch($businessA, 'A1');
        $user = User::factory()->create(['business_id' => $businessA->id, 'current_branch_id' => $a1->id]);

        $user->branchAccesses()->create(['business_id' => $businessA->id, 'branch_id' => $a1->id]);

        try {
            DB::table('users')->where('id', $user->id)->update(['business_id' => $businessB->id]);
            DB::statement('SET CONSTRAINTS user_branch_accesses_user_tenant_integrity IMMEDIATE');
            $this->fail('Expected cross-tenant user branch access to be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('User branch access must belong to the same business', $exception->getMessage());
        }
    }

    public function test_database_rejects_a_cross_tenant_direct_assignment(): void
    {
        $businessA = $this->business('A');
        $businessB = $this->business('B');
        $a1 = $this->branch($businessA, 'A1');
        $b1 = $this->branch($businessB, 'B1');
        $user = User::factory()->create(['business_id' => $businessA->id, 'current_branch_id' => $a1->id]);

        try {
            $user->branchAccesses()->create(['business_id' => $businessA->id, 'branch_id' => $b1->id]);
            DB::statement('SET CONSTRAINTS user_branch_accesses_tenant_integrity IMMEDIATE');
            $this->fail('Expected cross-tenant direct assignment to be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('User branch access must belong to the same business', $exception->getMessage());
        }

    }

    public function test_database_rejects_a_duplicate_direct_assignment(): void
    {
        $business = $this->business('A');
        $a1 = $this->branch($business, 'A1');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);

        $this->expectException(QueryException::class);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);
    }

    public function test_database_rejects_a_branch_tenant_change_that_would_invalidate_an_assignment(): void
    {
        $businessA = $this->business('A');
        $businessB = $this->business('B');
        $a1 = $this->branch($businessA, 'A1');
        $user = User::factory()->create(['business_id' => $businessA->id, 'current_branch_id' => $a1->id]);
        $user->branchAccesses()->create(['business_id' => $businessA->id, 'branch_id' => $a1->id]);

        try {
            DB::table('branches')->where('id', $a1->id)->update(['business_id' => $businessB->id]);
            DB::statement('SET CONSTRAINTS user_branch_accesses_branch_tenant_integrity IMMEDIATE');
            $this->fail('Expected cross-tenant branch change to be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('User branch access must belong to the same business', $exception->getMessage());
        }
    }

    public function test_tenant_user_creation_persists_selected_branch_access_without_granting_other_branches(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'max_users' => 10]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true]);
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $a3 = $this->branch($business, 'A3');
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'current_branch_id' => $a1->id,
            'all_branches_access' => true,
            'is_active' => true,
        ]);
        Permissions::assignRole($owner, 'owner');

        $this->actingAs($owner)
            ->post(route('users.store'), [
                'name' => 'Sucursal limitada',
                'email' => 'limited-'.uniqid().'@test.test',
                'role' => 'cashier',
                'password' => 'password',
                'password_confirmation' => 'password',
                'branch_access_mode' => 'selected',
                'branch_ids' => [$a1->id, $a2->id],
                'current_branch_id' => $a2->id,
            ])
            ->assertRedirect();

        $created = User::query()->where('email', 'like', 'limited-%')->firstOrFail();
        $this->assertFalse($created->all_branches_access);
        $this->assertSame($a2->id, $created->current_branch_id);
        $this->assertSame([$a1->id, $a2->id], $created->branchAccesses()->orderBy('branch_id')->pluck('branch_id')->all());
        $this->assertFalse(app(BranchAccessService::class)->canAccessBranch($created, $a3));
    }

    public function test_tenant_user_creation_can_grant_all_active_branches_explicitly(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'max_users' => 10]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true]);
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'current_branch_id' => $a1->id,
            'all_branches_access' => true,
            'is_active' => true,
        ]);
        Permissions::assignRole($owner, 'owner');

        $this->actingAs($owner)
            ->post(route('users.store'), [
                'name' => 'Todas las sucursales',
                'email' => 'all-'.uniqid().'@test.test',
                'role' => 'cashier',
                'password' => 'password',
                'password_confirmation' => 'password',
                'branch_access_mode' => 'all',
                'current_branch_id' => $a2->id,
            ])
            ->assertRedirect();

        $created = User::query()->where('email', 'like', 'all-%')->firstOrFail();
        $this->assertTrue($created->all_branches_access);
        $this->assertSame($a2->id, $created->current_branch_id);
        $this->assertTrue(app(BranchAccessService::class)->canAccessBranch($created, $a1));
        $this->assertTrue(app(BranchAccessService::class)->canAccessBranch($created, $a2));
    }

    public function test_single_branch_tenant_user_creation_assigns_the_only_active_branch_even_when_branches_are_disabled(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('single');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => false, 'max_users' => 10]);
        $main = BranchInventory::defaultBranch($business->id);
        $foreign = $this->branch($this->business('foreign'), 'OTHER');
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'current_branch_id' => $main->id,
            'all_branches_access' => true,
            'is_active' => true,
        ]);
        Permissions::assignRole($owner, 'owner');

        $this->actingAs($owner)
            ->post(route('users.store'), [
                'name' => 'Usuario único',
                'email' => 'single-'.uniqid().'@test.test',
                'role' => 'cashier',
                'password' => 'password',
                'password_confirmation' => 'password',
                'current_branch_id' => $foreign->id,
            ])
            ->assertRedirect();

        $created = User::query()->where('email', 'like', 'single-%')->firstOrFail();
        $this->assertSame($main->id, $created->current_branch_id);
        $this->assertFalse($created->all_branches_access);
        $this->assertSame([$main->id], $created->branchAccesses()->pluck('branch_id')->all());
    }

    public function test_disabled_multi_branch_tenant_requires_and_persists_the_selected_current_branch(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('legacy');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => false, 'max_users' => 10]);
        $main = BranchInventory::defaultBranch($business->id);
        $secondary = $this->branch($business, 'SECONDARY');
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'current_branch_id' => $main->id,
            'all_branches_access' => true,
            'is_active' => true,
        ]);
        Permissions::assignRole($owner, 'owner');

        $this->actingAs($owner)
            ->post(route('users.store'), [
                'name' => 'Usuario legado',
                'email' => 'legacy-'.uniqid().'@test.test',
                'role' => 'cashier',
                'password' => 'password',
                'password_confirmation' => 'password',
                'current_branch_id' => $secondary->id,
                'branch_access_mode' => 'all',
            ])
            ->assertRedirect();

        $created = User::query()->where('email', 'like', 'legacy-%')->firstOrFail();
        $this->assertSame($secondary->id, $created->current_branch_id);
        $this->assertFalse($created->all_branches_access);
        $this->assertSame([$secondary->id], $created->branchAccesses()->pluck('branch_id')->all());
    }

    public function test_tenant_user_update_switches_selected_and_all_access_without_leaving_an_invalid_current_branch(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'max_users' => 10]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true]);
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $a3 = $this->branch($business, 'A3');
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'current_branch_id' => $a1->id,
            'all_branches_access' => true,
            'is_active' => true,
        ]);
        Permissions::assignRole($owner, 'owner');
        $target = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'current_branch_id' => $a1->id,
            'is_active' => true,
        ]);
        $target->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);

        $this->actingAs($owner)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'role' => 'cashier',
                'is_active' => true,
                'branch_access_mode' => 'selected',
                'branch_ids' => [$a2->id],
                'current_branch_id' => $a2->id,
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->all_branches_access);
        $this->assertSame($a2->id, $target->current_branch_id);
        $this->assertSame([$a2->id], $target->branchAccesses()->pluck('branch_id')->all());

        $this->actingAs($owner)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'role' => 'cashier',
                'is_active' => true,
                'branch_access_mode' => 'all',
                'current_branch_id' => $a3->id,
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertTrue($target->all_branches_access);
        $this->assertSame($a3->id, $target->current_branch_id);
        $this->assertTrue(app(BranchAccessService::class)->canAccessBranch($target, $a1));
    }

    public function test_switch_permission_allows_only_explicitly_authorized_branches(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true]);
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $a3 = $this->branch($business, 'A3');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id, 'is_active' => true]);
        $user->branchAccesses()->createMany([
            ['business_id' => $business->id, 'branch_id' => $a1->id],
            ['business_id' => $business->id, 'branch_id' => $a2->id],
        ]);
        Permissions::assignDirectPermissions($user, [Permissions::BRANCHES_SWITCH]);

        $this->actingAs($user)
            ->post(route('branches.active'), ['branch_id' => $a2->id])
            ->assertRedirect();

        $this->assertSame($a2->id, $user->fresh()->current_branch_id);

        $this->actingAs($user)
            ->post(route('branches.active'), ['branch_id' => $a3->id])
            ->assertForbidden();
    }

    public function test_shared_branch_selector_exposes_only_authorized_active_branches(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        foreach (['branches', 'pos'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true]);
        }
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $this->branch($business, 'A3');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id, 'is_active' => true]);
        $user->branchAccesses()->createMany([
            ['business_id' => $business->id, 'branch_id' => $a1->id],
            ['business_id' => $business->id, 'branch_id' => $a2->id],
        ]);
        Permissions::assignDirectPermissions($user, [Permissions::POS_VIEW, Permissions::BRANCHES_SWITCH]);

        $this->actingAs($user)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('branches', 2)
                ->where('branches.0.id', $a1->id)
                ->where('branches.1.id', $a2->id)
                ->where('branch_can_switch', true));
    }

    public function test_branch_scoped_sale_purchase_and_cash_records_reject_an_unassigned_same_tenant_branch(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        foreach (['branches', 'pos', 'purchases', 'cash_register'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true]);
        }
        $a1 = $this->branch($business, 'A1');
        $a3 = $this->branch($business, 'A3');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id, 'is_active' => true]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);
        Permissions::assignDirectPermissions($user, [
            Permissions::SALES_VIEW,
            Permissions::PURCHASES_VIEW,
            Permissions::CASH_REGISTER_VIEW,
        ]);

        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $a3->id,
            'total' => 10,
            'payment_method' => 'cash',
            'created_by' => $user->id,
        ]);
        $purchase = Purchase::query()->create([
            'business_id' => $business->id,
            'branch_id' => $a3->id,
            'total' => 10,
            'payment_method' => 'cash',
            'created_by' => $user->id,
        ]);
        $session = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $a3->id,
            'opened_by' => $user->id,
            'status' => 'closed',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'counted_cash' => 0,
            'difference' => 0,
            'opened_at' => now(),
            'closed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('sales.show', $sale))->assertForbidden();
        $this->actingAs($user)->get(route('purchases.show', $purchase))->assertForbidden();
        $this->actingAs($user)->get(route('cash-register.closings.show', $session))->assertForbidden();
    }

    public function test_transfer_visibility_requires_authorization_for_both_origin_and_destination(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        foreach (['branches', 'inventory'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true]);
        }
        $a1 = $this->branch($business, 'A1');
        $a2 = $this->branch($business, 'A2');
        $a3 = $this->branch($business, 'A3');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id, 'is_active' => true]);
        $user->branchAccesses()->createMany([
            ['business_id' => $business->id, 'branch_id' => $a1->id],
            ['business_id' => $business->id, 'branch_id' => $a2->id],
        ]);
        Permissions::assignDirectPermissions($user, [Permissions::INVENTORY_TRANSFERS_VIEW]);

        $allowed = InventoryTransfer::query()->create([
            'business_id' => $business->id,
            'from_branch_id' => $a1->id,
            'to_branch_id' => $a2->id,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);
        $blocked = InventoryTransfer::query()->create([
            'business_id' => $business->id,
            'from_branch_id' => $a1->id,
            'to_branch_id' => $a3->id,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('inventory.transfers.show', $allowed))->assertOk();
        $this->actingAs($user)->get(route('inventory.transfers.show', $blocked))->assertForbidden();
    }

    public function test_route_cash_settlement_detail_rejects_an_unassigned_same_tenant_branch(): void
    {
        Permissions::syncDefaults();
        $business = $this->business('A');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true]);
        foreach (['branches', 'routes'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true]);
        }
        $a1 = $this->branch($business, 'A1');
        $a3 = $this->branch($business, 'A3');
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $a1->id, 'is_active' => true]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $a1->id]);
        Permissions::assignDirectPermissions($user, [Permissions::ROUTES_CASH_SETTLEMENTS_VIEW]);
        $settlement = RouteCashSettlement::query()->create([
            'business_id' => $business->id,
            'branch_id' => $a3->id,
            'collector_user_id' => $user->id,
            'recorded_by' => $user->id,
            'expected_amount' => 0,
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('routes.cash-settlements.show', $settlement))
            ->assertForbidden();
    }

    private function branch(Business $business, string $name): Branch
    {
        return Branch::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => $name,
            'is_active' => true,
            'fel_country' => 'GT',
        ]);
    }

    private function business(string $suffix): Business
    {
        return Business::query()->create([
            'name' => "Business {$suffix}",
            'slug' => "business-{$suffix}",
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
    }
}
