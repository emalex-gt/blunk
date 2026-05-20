<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CashExpense;
use App\Models\CashExpenseCategory;
use App\Models\CashRegisterSession;
use App\Models\TenantSetting;
use App\Support\CashRegister;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeCashRegister($request);

        $businessId = currentBusinessId();
        $timezone = tenantTimezone($businessId);
        $session = CashRegister::currentOpenSession($businessId);

        return Inertia::render('CashRegister/Index', [
            'openSession' => $session ? $this->sessionPayload($session, $timezone, true) : null,
            'expenseCategories' => CashExpenseCategory::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $this->authorizeCashRegister($request);

        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $businessId = currentBusinessId();
            CashRegister::ensureNoOpenSession($businessId);

            $session = CashRegisterSession::create([
                'business_id' => $businessId,
                'opened_by' => $request->user()->id,
                'status' => 'open',
                'opening_amount' => round((float) $data['opening_amount'], 2),
                'expected_cash' => 0,
                'opened_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            CashRegister::recordMovement(
                $session,
                'opening',
                (float) $data['opening_amount'],
                'cash_register_session',
                $session->id,
                'Apertura de caja',
                $request->user()->id,
            );
        });

        return back()->with('success', 'Caja abierta correctamente.');
    }

    public function expense(Request $request): RedirectResponse
    {
        $this->authorizeCashRegister($request);

        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $businessId = currentBusinessId();
            $session = CashRegister::requireOpenSession(
                $businessId,
                'Debes abrir caja antes de registrar gastos.',
                true,
            );
            $category = $this->resolveExpenseCategory(
                $businessId,
                $data['category_id'] ?? null,
                $data['category_name'] ?? null,
            );

            $expense = CashExpense::create([
                'business_id' => $businessId,
                'cash_register_session_id' => $session->id,
                'cash_expense_category_id' => $category?->id,
                'category' => $category?->name,
                'description' => $data['description'],
                'amount' => round((float) $data['amount'], 2),
                'created_by' => $request->user()->id,
            ]);

            CashRegister::recordMovement(
                $session,
                'expense',
                -1 * (float) $data['amount'],
                'cash_expense',
                $expense->id,
                $expense->description,
                $request->user()->id,
            );
        });

        return back()->with('success', 'Gasto registrado correctamente.');
    }

    public function close(Request $request): RedirectResponse
    {
        $this->authorizeCashRegister($request);

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $sessionId = DB::transaction(function () use ($request, $data) {
            $businessId = currentBusinessId();
            $session = CashRegister::requireOpenSession($businessId, 'No hay caja abierta.', true);
            $expected = CashRegister::expectedCash($session->id);
            $counted = round((float) $data['counted_cash'], 2);

            $session->update([
                'status' => 'closed',
                'closed_by' => $request->user()->id,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => round($counted - $expected, 2),
                'closed_at' => now(),
                'closing_notes' => $data['closing_notes'] ?? null,
            ]);

            return $session->id;
        });

        return redirect()
            ->route('cash-register.closings.show', $sessionId)
            ->with('success', 'Caja cerrada correctamente.')
            ->with('cash_closing_print_id', $sessionId);
    }

    public function closings(Request $request): Response
    {
        $this->authorizeCashRegister($request);

        $businessId = currentBusinessId();
        $timezone = tenantTimezone($businessId);

        return Inertia::render('CashRegister/Closings/Index', [
            'sessions' => CashRegisterSession::query()
                ->where('business_id', $businessId)
                ->where('status', 'closed')
                ->with(['openedBy:id,name', 'closedBy:id,name'])
                ->latest('closed_at')
                ->paginate(25)
                ->through(fn (CashRegisterSession $session) => $this->sessionPayload($session, $timezone)),
        ]);
    }

    public function closingShow(Request $request, CashRegisterSession $session): Response
    {
        $this->authorizeCashRegister($request);
        $this->ensureSessionBelongsToCurrentBusiness($session);

        $timezone = tenantTimezone($session->business_id);
        $session->load(['openedBy:id,name', 'closedBy:id,name']);

        return Inertia::render('CashRegister/Closings/Show', [
            'session' => $this->sessionPayload($session, $timezone, true),
        ]);
    }

    public function closingPrint(Request $request, CashRegisterSession $session)
    {
        $this->authorizeCashRegister($request);
        $this->ensureSessionBelongsToCurrentBusiness($session);

        $business = Business::query()->select('id', 'name', 'country')->find(currentBusinessId());
        $timezone = tenantTimezone($business);
        $settings = TenantSetting::query()->where('business_id', currentBusinessId())->first();
        $session->load(['openedBy:id,name', 'closedBy:id,name']);

        return view('cash-register.closing', [
            'paperSize' => ($business?->country ?? 'GT') === 'AR' ? 'A4' : 'Letter',
            'receiptFormat' => $settings?->receipt_format === 'document' ? 'document' : 'ticket',
            'company' => [
                'logo_url' => $settings?->company_logo_url,
                'name' => $settings?->company_name ?: $business?->name,
                'tax_id' => $settings?->company_tax_id,
                'address' => $settings?->company_address,
                'phone' => $settings?->company_phone,
            ],
            'business' => $business,
            'session' => $session,
            'summary' => CashRegister::summary($session),
            'movements' => CashRegister::movements($session),
            'timezone' => $timezone,
        ]);
    }

    private function sessionPayload(CashRegisterSession $session, string $timezone, bool $includeMovements = false): array
    {
        $session->loadMissing(['openedBy:id,name', 'closedBy:id,name']);
        $summary = CashRegister::summary($session);

        $payload = [
            'id' => $session->id,
            'status' => $session->status,
            'opening_amount' => (float) $session->opening_amount,
            'expected_cash' => (float) ($session->status === 'open' ? $summary['expected_cash'] : $session->expected_cash),
            'counted_cash' => $session->counted_cash !== null ? (float) $session->counted_cash : null,
            'difference' => $session->difference !== null ? (float) $session->difference : null,
            'opened_at' => $this->formatDateTime($session->opened_at, $timezone),
            'closed_at' => $this->formatDateTime($session->closed_at, $timezone),
            'opened_by' => $session->openedBy?->name,
            'closed_by' => $session->closedBy?->name,
            'notes' => $session->notes,
            'closing_notes' => $session->closing_notes,
            'summary' => $summary,
        ];

        if ($includeMovements) {
            $payload['movements'] = CashRegister::movements($session)->map(fn ($movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'type_label' => $this->movementLabel($movement->type),
                'description' => $movement->description,
                'amount' => (float) $movement->amount,
                'created_at' => $this->formatDateTime($movement->created_at, $timezone),
                'created_by' => $movement->createdBy?->name,
            ])->values();
        }

        return $payload;
    }

    private function formatDateTime($value, string $timezone): ?string
    {
        return $value ? Carbon::parse($value)->timezone($timezone)->format('Y-m-d H:i') : null;
    }

    private function movementLabel(string $type): string
    {
        return match ($type) {
            'opening' => 'Apertura',
            'sale_cash' => 'Venta en efectivo',
            'sale_cash_cancel' => 'Anulación de venta',
            'expense' => 'Gasto',
            'purchase_cash' => 'Compra desde caja',
            'closing_adjustment' => 'Ajuste de cierre',
            default => 'Movimiento',
        };
    }

    private function resolveExpenseCategory(int $businessId, mixed $categoryId, ?string $categoryName): ?CashExpenseCategory
    {
        if ($categoryId) {
            $category = CashExpenseCategory::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->find($categoryId);

            if (! $category) {
                throw ValidationException::withMessages([
                    'category_id' => 'La categoría seleccionada no pertenece a este negocio.',
                ]);
            }

            return $category;
        }

        $categoryName = trim((string) $categoryName);

        if ($categoryName === '') {
            return null;
        }

        $category = CashExpenseCategory::query()
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])
            ->first();

        if ($category) {
            return $category;
        }

        return CashExpenseCategory::create([
            'business_id' => $businessId,
            'name' => $categoryName,
            'is_active' => true,
        ]);
    }

    private function authorizeCashRegister(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user && ($user->is_super_admin || in_array($user->role, ['owner', 'admin', 'cashier'], true)),
            403,
        );
    }

    private function ensureSessionBelongsToCurrentBusiness(CashRegisterSession $session): void
    {
        abort_unless((int) $session->business_id === (int) currentBusinessId(), 403);
    }
}
