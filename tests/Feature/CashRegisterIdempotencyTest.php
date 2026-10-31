<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashExpense;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\OperationIdempotencyKey;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashRegisterIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permissions::syncDefaults();
    }

    public function test_expense_replays_do_not_create_extra_expenses_or_cash_movements(): void
    {
        [$business, $user, $branch] = $this->cashier([Permissions::CASH_REGISTER_EXPENSES]);
        $session = $this->openSession($business, $branch, $user);
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'category_name' => 'Operación',
            'description' => 'Compra de agua',
            'amount' => '25.50',
        ];

        foreach (range(1, 5) as $_) {
            $this->actingAs($user)
                ->post(route('cash-register.expenses.store'), $payload)
                ->assertRedirect();
        }

        $this->assertSame(1, CashExpense::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, CashMovement::query()
            ->where('cash_register_session_id', $session->id)
            ->where('type', 'expense')
            ->count());
        $this->assertDatabaseHas('operation_idempotency_keys', [
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'operation_type' => 'cash_register_expense',
            'idempotency_key' => $payload['idempotency_key'],
            'status' => 'completed',
            'result_type' => 'cash_expense',
        ]);
    }

    public function test_expense_rejects_a_changed_payload_for_the_same_key_without_new_effects(): void
    {
        [$business, $user, $branch] = $this->cashier([Permissions::CASH_REGISTER_EXPENSES]);
        $session = $this->openSession($business, $branch, $user);
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'description' => 'Gasto operativo',
            'amount' => '10.00',
        ];

        $this->actingAs($user)->post(route('cash-register.expenses.store'), $payload)->assertRedirect();
        $this->actingAs($user)
            ->post(route('cash-register.expenses.store'), [...$payload, 'amount' => '15.00'])
            ->assertStatus(409);

        $this->assertSame(1, CashExpense::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, CashMovement::query()
            ->where('cash_register_session_id', $session->id)
            ->where('type', 'expense')
            ->count());
    }

    public function test_opening_replays_create_one_session_and_opening_movement(): void
    {
        [$business, $user, $branch] = $this->cashier([Permissions::CASH_REGISTER_OPEN]);
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'opening_amount' => '100.00',
            'notes' => 'Apertura idempotente',
        ];

        foreach (range(1, 5) as $_) {
            $this->actingAs($user)->post(route('cash-register.open'), $payload)->assertRedirect();
        }

        $this->assertSame(1, CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('status', 'open')
            ->count());
        $this->assertSame(1, CashMovement::query()
            ->where('business_id', $business->id)
            ->where('type', 'opening')
            ->count());
        $this->assertDatabaseHas('operation_idempotency_keys', [
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'operation_type' => 'cash_register_open',
            'idempotency_key' => $payload['idempotency_key'],
            'status' => 'completed',
            'result_type' => 'cash_register_session',
        ]);
    }

    public function test_distinct_opening_keys_cannot_create_a_second_open_session_in_the_same_branch(): void
    {
        [$business, $user, $branch] = $this->cashier([Permissions::CASH_REGISTER_OPEN]);

        $this->actingAs($user)->post(route('cash-register.open'), [
            'idempotency_key' => (string) Str::uuid(),
            'opening_amount' => '0.00',
        ])->assertRedirect();

        foreach (range(1, 5) as $_) {
            $this->actingAs($user)
                ->post(route('cash-register.open'), [
                    'idempotency_key' => (string) Str::uuid(),
                    'opening_amount' => '0.00',
                ])
                ->assertSessionHasErrors('opening_amount');
        }

        $this->assertSame(1, CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('status', 'open')
            ->count());
    }

    public function test_open_sessions_and_idempotency_scopes_are_isolated_by_branch_and_tenant(): void
    {
        [$business, $user, $branch] = $this->cashier([Permissions::CASH_REGISTER_OPEN]);
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Secundaria',
            'code' => 'SECOND',
            'is_active' => true,
        ]);
        $key = (string) Str::uuid();

        $this->actingAs($user)->post(route('cash-register.open'), [
            'idempotency_key' => $key,
            'opening_amount' => '0.00',
        ])->assertRedirect();

        $user->update(['current_branch_id' => $otherBranch->id]);
        $this->actingAs($user->fresh())->post(route('cash-register.open'), [
            'idempotency_key' => $key,
            'opening_amount' => '0.00',
        ])->assertRedirect();

        [$otherBusiness, $otherUser, $otherBusinessBranch] = $this->cashier([Permissions::CASH_REGISTER_OPEN]);
        $this->actingAs($otherUser)->post(route('cash-register.open'), [
            'idempotency_key' => $key,
            'opening_amount' => '0.00',
        ])->assertRedirect();

        $this->assertSame(2, CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('status', 'open')
            ->count());
        $this->assertSame(1, CashRegisterSession::query()
            ->where('business_id', $otherBusiness->id)
            ->where('branch_id', $otherBusinessBranch->id)
            ->where('status', 'open')
            ->count());
        $this->assertSame(3, OperationIdempotencyKey::query()
            ->where('operation_type', 'cash_register_open')
            ->where('idempotency_key', $key)
            ->count());
    }

    /** @return array{Business, User, Branch} */
    private function cashier(array $permissionKeys): array
    {
        $business = Business::query()->create([
            'name' => 'Caja idempotente '.uniqid(),
            'slug' => 'cash-idempotency-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
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
        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'branches',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $branch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Principal',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'business_id' => $business->id,
            'key' => 'cash-idempotency-'.uniqid(),
            'name' => 'Cajero idempotente',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));

        $user = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
            'all_branches_access' => true,
            'role' => $role->key,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        $user->roles()->sync([$role->id]);

        return [$business, $user, $branch];
    }

    private function openSession(Business $business, Branch $branch, User $user): CashRegisterSession
    {
        return CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
    }
}
