<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\TenantSetting;
use App\Support\AccountsReceivable;
use App\Support\BranchInventory;
use App\Support\BusinessLogo;
use App\Support\Credits;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountReceivableController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureAvailable($request, Permissions::CREDITS_ACCOUNTS_VIEW);
        $businessId = currentBusinessId();
        $search = trim((string) $request->query('customer_search', ''));
        $status = (string) $request->query('status', 'all');
        $minBalance = $request->filled('min_balance') ? (float) $request->query('min_balance') : null;

        $accounts = CustomerCreditAccount::query()
            ->where('business_id', $businessId)
            ->where('current_balance', '>', 0)
            ->with('customer:id,business_id,name,doc_type,doc_number')
            ->withMax('movements', 'created_at')
            ->when($search !== '', fn ($query) => $query->whereHas('customer', fn ($customer) => $customer
                ->where('name', 'like', "%{$search}%")
                ->orWhere('doc_number', 'like', "%{$search}%")))
            ->when($minBalance !== null, fn ($query) => $query->where('current_balance', '>=', $minBalance))
            ->when($status === 'blocked', fn ($query) => $query->where('is_blocked', true))
            ->when($status === 'active', fn ($query) => $query->where('is_blocked', false))
            ->orderByDesc('current_balance')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Credits/Accounts', [
            'accounts' => $accounts,
            'filters' => compact('search', 'status', 'minBalance'),
        ]);
    }

    public function payments(Request $request): Response
    {
        abort_unless(Credits::enabled(currentBusinessId()), 403, 'El módulo de créditos no está habilitado.');
        abort_unless(
            Permissions::userHas($request->user(), Permissions::CREDITS_PAYMENTS_VIEW)
            || Permissions::userHas($request->user(), Permissions::CREDITS_PAYMENTS_CREATE),
            403,
        );
        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);
        $business = Business::query()->find($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $search = trim((string) $request->query('customer_search', ''));
        $method = trim((string) $request->query('payment_method', ''));
        $canViewPayments = Permissions::userHas($request->user(), Permissions::CREDITS_PAYMENTS_VIEW);

        $payments = CustomerCreditPayment::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branch->id)
            ->when(! $canViewPayments, fn ($query) => $query->whereRaw('1 = 0'))
            ->whereBetween('created_at', [$range->start, $range->end])
            ->with(['customer:id,name,doc_number', 'branch:id,name', 'createdBy:id,name'])
            ->when($search !== '', fn ($query) => $query->whereHas('customer', fn ($customer) => $customer
                ->where('name', 'like', "%{$search}%")
                ->orWhere('doc_number', 'like', "%{$search}%")))
            ->when($method !== '', fn ($query) => $query->where('payment_method', $method))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Credits/Payments', [
            'payments' => $payments,
            'filters' => [
                'date_from' => $range->dateFrom,
                'date_to' => $range->dateTo,
                'customer_search' => $search,
                'payment_method' => $method,
            ],
            'customers' => Customer::query()
                ->where('business_id', $businessId)
                ->whereHas('creditAccount', fn ($query) => $query->where('current_balance', '>', 0))
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'doc_number']),
            'branch' => $branch->only(['id', 'name']),
            'can_view_payments' => $canViewPayments,
        ]);
    }

    public function statement(Request $request, Customer $customer): Response
    {
        $this->ensureAvailable($request, Permissions::CREDITS_STATEMENT_VIEW);
        $this->ensureCustomer($customer);
        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);
        $business = Business::query()->find($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $account = AccountsReceivable::accountFor($customer, $branch->id);

        $movements = CustomerAccountMovement::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branch->id)
            ->where('customer_id', $customer->id)
            ->whereBetween('created_at', [$range->start, $range->end])
            ->with(['sale:id,business_number', 'payment:id,payment_number', 'createdBy:id,name', 'branch:id,name'])
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Credits/Statement', [
            'customer' => $customer->only(['id', 'name', 'doc_type', 'doc_number', 'address']),
            'account' => $account,
            'movements' => $movements,
            'filters' => ['date_from' => $range->dateFrom, 'date_to' => $range->dateTo],
            'branch' => $branch->only(['id', 'name']),
        ]);
    }

    public function storePayment(Request $request): RedirectResponse
    {
        $this->ensureAvailable($request, Permissions::CREDITS_PAYMENTS_CREATE);
        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'check', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $customer = Customer::query()
            ->where('business_id', $businessId)
            ->findOrFail($data['customer_id']);

        $idempotencyResult = app(IdempotencyService::class)->run(
            $businessId,
            $branch->id,
            $request->user()->id,
            'credit_payment_store',
            $data['idempotency_key'],
            $this->paymentIdempotencyPayload($data, $businessId, $branch->id, $request->user()->id, 'credit_payment_store'),
            function () use ($customer, $branch, $data, $request) {
                $payment = AccountsReceivable::recordPayment(
                    $customer,
                    $branch->id,
                    (float) $data['amount'],
                    $data['payment_method'],
                    $data['reference'] ?? null,
                    $data['notes'] ?? null,
                    $request->user(),
                );

                return [
                    'result_id' => $payment->id,
                    'response_payload' => ['payment_id' => $payment->id],
                ];
            },
            'credit_payment',
        );

        return back()
            ->with('success', 'Abono registrado correctamente.')
            ->with('credit_payment_print_url', route('credits.payments.print', $idempotencyResult->resultId));
    }

    public function cancelPayment(Request $request, CustomerCreditPayment $payment): RedirectResponse
    {
        $this->ensureAvailable($request, Permissions::CREDITS_PAYMENTS_CANCEL);
        abort_unless((int) $payment->business_id === (int) currentBusinessId(), 403);
        abort_unless((int) $payment->branch_id === (int) BranchInventory::activeBranch(currentBusinessId())->id, 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        app(IdempotencyService::class)->run(
            (int) $payment->business_id,
            (int) $payment->branch_id,
            $request->user()->id,
            'credit_payment_cancel',
            $data['idempotency_key'],
            [
                'business_id' => (int) $payment->business_id,
                'branch_id' => (int) $payment->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'credit_payment_cancel',
                'payment_id' => $payment->id,
            ],
            fn () => [
                'result_id' => tap($payment, fn ($payment) => AccountsReceivable::cancelPayment($payment, $request->user()))->id,
                'response_payload' => ['payment_id' => $payment->id],
            ],
            'credit_payment',
        );

        return back()->with('success', 'Abono anulado correctamente.');
    }

    public function updateAccount(Request $request, Customer $customer): RedirectResponse
    {
        $this->ensureAvailable($request, Permissions::CREDITS_LIMITS_MANAGE);
        $this->ensureCustomer($customer);
        $data = $request->validate([
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_blocked' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $account = AccountsReceivable::accountFor($customer, BranchInventory::activeBranch(currentBusinessId())->id);
        $account->update($data);

        return back()->with('success', 'Configuración de crédito actualizada.');
    }

    public function printPayment(Request $request, CustomerCreditPayment $payment)
    {
        abort_unless(
            Permissions::userHas($request->user(), Permissions::CREDITS_PRINT)
            || Permissions::userHas($request->user(), Permissions::CREDITS_PAYMENTS_VIEW),
            403,
        );
        abort_unless(Credits::enabled(currentBusinessId()), 403, 'El módulo de créditos no está habilitado.');
        abort_unless((int) $payment->business_id === (int) currentBusinessId(), 403);
        abort_unless((int) $payment->branch_id === (int) BranchInventory::activeBranch(currentBusinessId())->id, 403);
        $payment->load(['customer', 'branch', 'createdBy']);
        $business = Business::query()->findOrFail($payment->business_id);
        $settings = TenantSetting::query()->where('business_id', $business->id)->first();
        $movement = CustomerAccountMovement::query()
            ->where('business_id', $business->id)
            ->where('payment_id', $payment->id)
            ->where('type', 'payment')
            ->firstOrFail();
        $format = $settings?->receipt_format === 'document' ? 'document' : 'ticket';

        return view("credits.payment-{$format}", [
            'business' => $business,
            'branch' => $payment->branch,
            'payment' => $payment,
            'movement' => $movement,
            'logoUrl' => BusinessLogo::forPrint($business, $payment->branch),
            'previousBalance' => round((float) $movement->balance_after + (float) $payment->amount, 2),
            'newBalance' => (float) $movement->balance_after,
        ]);
    }

    private function ensureAvailable(Request $request, string $permission): void
    {
        abort_unless(Credits::enabled(currentBusinessId()), 403, 'El módulo de créditos no está habilitado.');
        abort_unless(Permissions::userHas($request->user(), $permission), 403);
    }

    private function ensureCustomer(Customer $customer): void
    {
        abort_unless((int) $customer->business_id === (int) currentBusinessId(), 403);
    }

    private function paymentIdempotencyPayload(array $data, int $businessId, int $branchId, int $userId, string $operationType): array
    {
        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'operation_type' => $operationType,
            'customer_id' => (int) ($data['customer_id'] ?? 0),
            'amount' => round((float) ($data['amount'] ?? 0), 4),
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
