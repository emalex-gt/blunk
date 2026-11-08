<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CashExpense;
use App\Models\CashExpenseCategory;
use App\Models\CashRegisterSession;
use App\Services\BranchAccessService;
use App\Models\TenantSetting;
use App\Support\BranchInventory;
use App\Support\CashRegister;
use App\Support\CashRegisterClosingOperations;
use App\Support\CashRegisterClosingSales;
use App\Support\CashRegisterClosingSnapshot;
use App\Support\IdempotencyService;
use Illuminate\Database\QueryException;
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
        $businessId = currentBusinessId();
        $timezone = tenantTimezone($businessId);
        $session = CashRegister::currentOpenSession($businessId);

        return Inertia::render('CashRegister/Index', [
            'openSession' => $session ? $this->sessionPayload($session, $timezone, true, [
                'method' => (string) $request->query('payment_method', 'all'),
                'operation' => (string) $request->query('operation', 'all'),
            ]) : null,
            'expenseCategories' => CashExpenseCategory::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);

        try {
            app(IdempotencyService::class)->run(
                $businessId,
                $branch->id,
                $request->user()->id,
                'cash_register_open',
                $data['idempotency_key'],
                [
                    'business_id' => $businessId,
                    'branch_id' => $branch->id,
                    'opening_amount' => round((float) $data['opening_amount'], 2),
                    'notes' => $data['notes'] ?? null,
                ],
                function () use ($request, $data, $businessId, $branch): int {
                    return DB::transaction(function () use ($request, $data, $businessId, $branch): int {
                        if (CashRegister::currentOpenSession($businessId, true, $branch->id)) {
                            throw ValidationException::withMessages([
                                'opening_amount' => 'Ya hay una caja abierta.',
                            ]);
                        }

                        $session = CashRegisterSession::create([
                            'business_id' => $businessId,
                            'branch_id' => $branch->id,
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

                        return $session->id;
                    });
                },
                'cash_register_session',
            );
        } catch (QueryException $exception) {
            if ($this->isOpenSessionUniquenessViolation($exception)) {
                throw ValidationException::withMessages([
                    'opening_amount' => 'Ya hay una caja abierta.',
                ]);
            }

            throw $exception;
        }

        return back()->with('success', 'Caja abierta correctamente.');
    }

    public function expense(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'category_id' => ['nullable', 'integer'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);

        app(IdempotencyService::class)->run(
            $businessId,
            $branch->id,
            $request->user()->id,
            'cash_register_expense',
            $data['idempotency_key'],
            [
                'business_id' => $businessId,
                'branch_id' => $branch->id,
                'category_id' => $data['category_id'] ?? null,
                'category_name' => $data['category_name'] ?? null,
                'description' => $data['description'],
                'amount' => round((float) $data['amount'], 2),
            ],
            function () use ($request, $data, $businessId, $branch): int {
                return DB::transaction(function () use ($request, $data, $businessId, $branch): int {
                    $session = CashRegister::requireOpenSession(
                        $businessId,
                        'Debes abrir caja antes de registrar gastos.',
                        true,
                        $branch->id,
                    );
                    $category = $this->resolveExpenseCategory(
                        $businessId,
                        $data['category_id'] ?? null,
                        $data['category_name'] ?? null,
                    );

                    $expense = CashExpense::create([
                        'business_id' => $businessId,
                        'branch_id' => $session->branch_id,
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

                    return $expense->id;
                });
            },
            'cash_expense',
        );

        return back()->with('success', 'Gasto registrado correctamente.');
    }

    public function cancelExpense(Request $request, CashExpense $expense): RedirectResponse
    {
        $data = $request->validate([
            'cancellation_note' => ['required', 'string', 'min:3', 'max:2000'],
        ], [
            'cancellation_note.required' => 'Indica el motivo de la anulación.',
        ]);

        DB::transaction(function () use ($request, $expense, $data) {
            $businessId = currentBusinessId();
            $session = CashRegister::requireOpenSession(
                $businessId,
                'No hay una caja abierta. Sólo puedes anular gastos de la caja actual.',
                true,
            );

            $lockedExpense = CashExpense::query()
                ->where('business_id', $businessId)
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedExpense->branch_id !== (int) $session->branch_id) {
                abort(403);
            }

            if ((int) $lockedExpense->cash_register_session_id !== (int) $session->id) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Este gasto pertenece a una caja anterior y ya no puede anularse.',
                ]);
            }

            if ($lockedExpense->status === CashExpense::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'expense' => 'Este gasto ya fue anulado.',
                ]);
            }

            $lockedExpense->update([
                'status' => CashExpense::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_note' => $data['cancellation_note'],
            ]);

            CashRegister::recordMovement(
                $session,
                'expense_cancel',
                (float) $lockedExpense->amount,
                'cash_expense',
                $lockedExpense->id,
                "Anulación de gasto: {$lockedExpense->description} — Motivo: {$data['cancellation_note']}",
                $request->user()->id,
            );
        });

        return back()->with('success', 'Gasto anulado correctamente.');
    }

    public function close(Request $request): RedirectResponse
    {
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

            CashRegisterClosingSnapshot::capture($session->refresh());

            return $session->id;
        });

        return redirect()
            ->route('cash-register.closings.show', $sessionId)
            ->with('success', 'Caja cerrada correctamente.')
            ->with('cash_closing_print_id', $sessionId);
    }

    public function closings(Request $request): Response
    {
        $businessId = currentBusinessId();
        $timezone = tenantTimezone($businessId);

        return Inertia::render('CashRegister/Closings/Index', [
            'sessions' => CashRegisterSession::query()
                ->where('business_id', $businessId)
                ->when(BranchInventory::branchesEnabled($businessId), fn ($query) => $query->where('branch_id', BranchInventory::activeBranch($businessId)->id))
                ->where('status', 'closed')
                ->with(['openedBy:id,name', 'closedBy:id,name', 'closingSnapshot'])
                ->latest('closed_at')
                ->paginate(25)
                ->through(fn (CashRegisterSession $session) => $this->sessionPayload($session, $timezone)),
        ]);
    }

    public function closingShow(Request $request, CashRegisterSession $session): Response
    {
        $this->ensureSessionBelongsToCurrentBusiness($session);
        $this->ensureSessionBelongsToActiveBranch($request, $session);

        $timezone = tenantTimezone($session->business_id);
        $session->load(['openedBy:id,name', 'closedBy:id,name', 'closingSnapshot']);

        return Inertia::render('CashRegister/Closings/Show', [
            'session' => $this->sessionPayload($session, $timezone, true),
        ]);
    }

    public function closingPrint(Request $request, CashRegisterSession $session)
    {
        $this->ensureSessionBelongsToCurrentBusiness($session);
        $this->ensureSessionBelongsToActiveBranch($request, $session);

        $business = Business::query()->select('id', 'name', 'country')->find(currentBusinessId());
        $timezone = tenantTimezone($business);
        $settings = TenantSetting::query()->where('business_id', currentBusinessId())->first();
        $session->load(['openedBy:id,name', 'closedBy:id,name', 'closingSnapshot']);
        $closingContract = CashRegisterClosingSnapshot::forSession($session);

        return view('cash-register.closing', [
            'paperSize' => ($business?->country ?? 'GT') === 'AR' ? 'A4' : 'Letter',
            'receiptFormat' => $settings?->receipt_format === 'document' ? 'document' : 'ticket',
            'company' => [
                'logo_url' => $settings?->company_logo_url,
                'name' => $closingContract['header']['business_name'] ?: ($settings?->company_name ?: $business?->name),
                'tax_id' => $closingContract['header']['business_tax_id'] ?? $settings?->company_tax_id,
                'address' => $closingContract['header']['business_address'] ?? $settings?->company_address,
                'phone' => $closingContract['header']['business_phone'] ?? $settings?->company_phone,
            ],
            'business' => $business,
            'session' => $session,
            'summary' => $closingContract['cash_summary'],
            'movements' => $closingContract['movements'],
            'paymentOperations' => $closingContract['payment_operations'],
            'salesSummary' => $closingContract['commercial'],
            'closingContract' => $closingContract,
            'timezone' => $timezone,
        ]);
    }

    private function sessionPayload(CashRegisterSession $session, string $timezone, bool $includeMovements = false, ?array $paymentFilters = null): array
    {
        $session->loadMissing(['openedBy:id,name', 'closedBy:id,name', 'closingSnapshot']);
        $closingContract = CashRegisterClosingSnapshot::forSession($session);
        $summary = $closingContract['cash_summary'];

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
            'closing_contract' => $closingContract,
            'closing_source' => $closingContract['source'],
        ];

        if ($includeMovements) {
            $payload['payment_operations'] = $session->status === 'closed' && $session->closingSnapshot
                ? $closingContract['payment_operations']
                : CashRegisterClosingOperations::forSession($session, (string) ($paymentFilters['method'] ?? 'all'), (string) ($paymentFilters['operation'] ?? 'all'));
            // Inertia envía fechas legibles del tenant; la impresión recibe los Carbon originales.
            foreach (['details', 'incidents'] as $list) {
                $payload['payment_operations'][$list] = array_map(
                    fn (array $operation): array => [
                        ...$operation,
                        'occurred_at' => $this->formatDateTime($operation['occurred_at'] ?? null, $timezone),
                    ],
                    $payload['payment_operations'][$list],
                );
            }
            $movements = $session->status === 'closed' && $session->closingSnapshot
                ? collect($closingContract['movements'])->map(fn (array $movement) => (object) $movement)
                : CashRegister::movements($session);
            $expenseIds = $movements
                ->where('reference_type', 'cash_expense')
                ->pluck('reference_id')
                ->filter()
                ->unique()
                ->values();
            $expenses = CashExpense::query()
                ->where('business_id', $session->business_id)
                ->whereIn('id', $expenseIds)
                ->with('cancelledBy:id,name')
                ->get()
                ->keyBy('id');

            $payload['movements'] = $movements->map(function ($movement) use ($expenses, $timezone) {
                $expense = $movement->reference_type === 'cash_expense'
                    ? $expenses->get((int) $movement->reference_id)
                    : null;

                return [
                'id' => $movement->id,
                'type' => $movement->type,
                'type_label' => $this->movementLabel($movement->type),
                'description' => $movement->description,
                'amount' => (float) $movement->amount,
                'created_at' => $this->formatDateTime($movement->created_at, $timezone),
                'created_by' => $movement->created_by ?? $movement->createdBy?->name,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'expense_status' => $expense?->status,
                'expense_cancelled_at' => $this->formatDateTime($expense?->cancelled_at, $timezone),
                'expense_cancelled_by' => $expense?->cancelledBy?->name,
                'expense_cancellation_note' => $expense?->cancellation_note,
            ];
            })->values();
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
            'sale_refund_cash' => 'Reembolso de venta',
            'sale_cash_cancel' => 'Anulación de venta',
            'expense' => 'Gasto',
            'expense_cancel' => 'Anulación de gasto',
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

    private function ensureSessionBelongsToCurrentBusiness(CashRegisterSession $session): void
    {
        abort_unless((int) $session->business_id === (int) currentBusinessId(), 403);
    }

    private function ensureSessionBelongsToActiveBranch(Request $request, CashRegisterSession $session): void
    {
        $businessId = currentBusinessId();

        if (BranchInventory::branchesEnabled($businessId)) {
            app(BranchAccessService::class)->assertCanAccessBranch($request->user(), (int) $session->branch_id, $businessId);
        }
    }

    private function isOpenSessionUniquenessViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'cash_register_sessions_one_open_per_branch');
    }
}
