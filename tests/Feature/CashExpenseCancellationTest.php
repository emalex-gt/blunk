<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashExpense;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\CashRegister;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashExpenseCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_it_cancels_an_active_expense_with_one_positive_audit_movement(): void
    {
        [$business, $user, $session] = $this->cashierWithOpenSession([
            Permissions::CASH_REGISTER_VIEW,
            Permissions::CASH_REGISTER_EXPENSES,
            Permissions::CASH_REGISTER_EXPENSES_CANCEL,
        ]);
        $expense = $this->expense($business, $session, $user, 100, 'Compra de agua');

        $this->actingAs($user)
            ->post(route('cash-register.expenses.cancel', $expense), ['cancellation_note' => 'Registro duplicado'])
            ->assertRedirect();

        $expense->refresh();
        $this->assertSame(CashExpense::STATUS_CANCELLED, $expense->status);
        $this->assertNotNull($expense->cancelled_at);
        $this->assertSame($user->id, $expense->cancelled_by);
        $this->assertSame('Registro duplicado', $expense->cancellation_note);
        $this->assertSame(1, CashMovement::query()->where('cash_register_session_id', $session->id)->where('type', 'expense')->count());
        $this->assertSame(1, CashMovement::query()->where('cash_register_session_id', $session->id)->where('type', 'expense_cancel')->count());
        $this->assertSame(0.0, CashRegister::expectedCash($session->id));
        $this->assertSame(100.0, CashRegister::summary($session)['expenses']);
        $this->assertSame(100.0, CashRegister::summary($session)['expense_cancellations']);
    }

    public function test_cancellation_note_is_required_and_a_cancelled_expense_cannot_be_cancelled_twice(): void
    {
        [$business, $user, $session] = $this->cashierWithOpenSession([Permissions::CASH_REGISTER_EXPENSES_CANCEL]);
        $expense = $this->expense($business, $session, $user, 100);

        $this->actingAs($user)
            ->post(route('cash-register.expenses.cancel', $expense), [])
            ->assertSessionHasErrors('cancellation_note');
        $this->assertSame(CashExpense::STATUS_ACTIVE, $expense->fresh()->status);

        $this->actingAs($user)->post(route('cash-register.expenses.cancel', $expense), ['cancellation_note' => 'Corrección válida'])->assertRedirect();
        $this->actingAs($user)
            ->post(route('cash-register.expenses.cancel', $expense), ['cancellation_note' => 'Segundo intento'])
            ->assertSessionHasErrors('expense');

        $this->assertSame(1, CashMovement::query()->where('reference_type', 'cash_expense')->where('reference_id', $expense->id)->where('type', 'expense_cancel')->count());
    }

    public function test_expenses_from_closed_or_previous_sessions_cannot_be_cancelled(): void
    {
        [$business, $user, $session] = $this->cashierWithOpenSession([Permissions::CASH_REGISTER_EXPENSES_CANCEL]);
        $expense = $this->expense($business, $session, $user, 100);
        $session->update(['status' => 'closed', 'closed_at' => now()]);

        $this->actingAs($user)
            ->post(route('cash-register.expenses.cancel', $expense), ['cancellation_note' => 'Corrección válida'])
            ->assertSessionHasErrors('cash_register');

        $newSession = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $session->branch_id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now()->addSecond(),
        ]);

        $this->actingAs($user)
            ->post(route('cash-register.expenses.cancel', $expense), ['cancellation_note' => 'Corrección válida'])
            ->assertSessionHasErrors('cash_register');

        $this->assertSame(CashExpense::STATUS_ACTIVE, $expense->fresh()->status);
        $this->assertSame(0, CashMovement::query()->where('cash_register_session_id', $newSession->id)->where('type', 'expense_cancel')->count());
    }

    public function test_other_tenant_and_missing_permission_cannot_cancel_an_expense(): void
    {
        [$business, $user, $session] = $this->cashierWithOpenSession([Permissions::CASH_REGISTER_EXPENSES_CANCEL]);
        $expense = $this->expense($business, $session, $user, 100);
        [, $withoutPermission] = $this->cashierWithOpenSession([Permissions::CASH_REGISTER_VIEW]);

        $this->actingAs($withoutPermission)
            ->post(route('cash-register.expenses.cancel', $expense), ['cancellation_note' => 'No autorizado'])
            ->assertForbidden();

        [$otherBusiness, $otherUser, $otherSession] = $this->cashierWithOpenSession([Permissions::CASH_REGISTER_EXPENSES_CANCEL]);
        $otherExpense = $this->expense($otherBusiness, $otherSession, $otherUser, 40);

        $this->actingAs($user)
            ->post(route('cash-register.expenses.cancel', $otherExpense), ['cancellation_note' => 'Otro tenant'])
            ->assertNotFound();
    }

    public function test_an_expense_from_another_branch_cannot_be_cancelled(): void
    {
        [$business, $user, $currentSession] = $this->cashierWithOpenSession([Permissions::CASH_REGISTER_EXPENSES_CANCEL]);
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Otra sucursal',
            'code' => 'OTHER',
            'is_active' => true,
        ]);
        $otherSession = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $otherBranch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now()->subMinute(),
        ]);
        $expense = $this->expense($business, $otherSession, $user, 100);

        $this->actingAs($user)
            ->post(route('cash-register.expenses.cancel', $expense), ['cancellation_note' => 'Otra sucursal'])
            ->assertForbidden();

        $this->assertSame(CashExpense::STATUS_ACTIVE, $expense->fresh()->status);
        $this->assertSame(0, CashMovement::query()->where('cash_register_session_id', $currentSession->id)->where('type', 'expense_cancel')->count());
    }

    public function test_cash_register_source_uses_the_cancel_permission_and_a_confirmation_modal(): void
    {
        $source = file_get_contents(resource_path('js/Pages/CashRegister/Index.tsx'));

        $this->assertStringContainsString("const canCancelExpenses = can('cash_register.expenses.cancel');", $source);
        $this->assertStringContainsString("route('cash-register.expenses.cancel', expenseToCancel.reference_id)", $source);
        $this->assertStringContainsString('Confirmar anulación', $source);
        $this->assertStringContainsString('La anulación no elimina el gasto.', $source);
    }

    /** @return array{Business, User, CashRegisterSession} */
    private function cashierWithOpenSession(array $permissionKeys): array
    {
        $business = Business::query()->create([
            'name' => 'Caja '.uniqid(),
            'slug' => 'cash-cancel-'.uniqid(),
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
            'key' => 'cash-cancel-'.uniqid(),
            'name' => 'Caja anulaciones',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));

        $branch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Principal',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $branch->id,
            'role' => $role->key,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        $user->roles()->sync([$role->id]);

        $session = CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);

        return [$business, $user, $session];
    }

    private function expense(Business $business, CashRegisterSession $session, User $user, float $amount, string $description = 'Gasto'): CashExpense
    {
        $expense = CashExpense::query()->create([
            'business_id' => $business->id,
            'branch_id' => $session->branch_id,
            'cash_register_session_id' => $session->id,
            'description' => $description,
            'amount' => $amount,
            'created_by' => $user->id,
        ]);
        CashRegister::recordMovement($session, 'expense', -$amount, 'cash_expense', $expense->id, $description, $user->id);

        return $expense;
    }
}
