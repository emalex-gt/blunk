<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Branch;
use App\Models\CreditCustomerTransfer;
use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\Customer;
use App\Models\Product;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\BusinessLogo;
use App\Support\Credits;
use App\Support\GuatemalaNitCustomerResolver;
use App\Support\IdempotencyService;
use App\Support\Inventory\StockPolicy;
use App\Support\OperationDrafts;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CreditReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureCreditsAvailable($request, Permissions::CREDITS_VIEW);
        $businessId = currentBusinessId();
        $search = trim((string) $request->query('search', ''));

        $customers = CreditReceipt::query()
            ->selectRaw('customer_id, customer_name, customer_doc_number, SUM(pending_total) as pending_total, COUNT(*) as receipts_count, MAX(updated_at) as last_movement_at')
            ->where('business_id', $businessId)
            ->when(! BranchInventory::canSwitchBranches($request->user()), fn ($query) => $query->where('branch_id', BranchInventory::activeBranch($businessId)->id))
            ->whereIn('status', ['pending', 'partially_invoiced'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_doc_number', 'like', "%{$search}%");
            }))
            ->groupBy('customer_id', 'customer_name', 'customer_doc_number')
            ->orderByDesc('last_movement_at')
            ->get();

        return Inertia::render('Credits/Index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    public function customer(Customer $customer): Response
    {
        $this->ensureCreditsAvailable(request(), Permissions::CREDITS_VIEW);
        abort_unless((int) $customer->business_id === (int) currentBusinessId(), 403);

        $receipts = CreditReceipt::query()
            ->where('business_id', currentBusinessId())
            ->where('customer_id', $customer->id)
            ->when(! BranchInventory::canSwitchBranches(request()->user()), fn ($query) => $query->where('branch_id', BranchInventory::activeBranch(currentBusinessId())->id))
            ->whereIn('status', ['pending', 'partially_invoiced'])
            ->with('lines')
            ->latest()
            ->get();

        return Inertia::render('Credits/Customer', [
            'customer' => $customer,
            'receipts' => $receipts,
            'pending_total' => round((float) $receipts->sum('pending_total'), 2),
            'pending_lines' => $receipts->flatMap->lines
                ->where('qty_pending', '>', 0)
                ->values(),
        ]);
    }

    public function show(CreditReceipt $creditReceipt): Response
    {
        $this->ensureCreditsAvailable(request(), Permissions::CREDITS_VIEW);
        $this->authorizeReceipt($creditReceipt);

        return Inertia::render('Credits/Show', [
            'receipt' => $creditReceipt->load(['customer', 'branch', 'lines.product']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = currentBusinessId();
        $this->ensureCreditsAvailable($request, Permissions::CREDITS_CREATE);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'branch_id' => ['nullable', 'integer'],
            'draft_id' => ['nullable', 'integer', 'exists:operation_drafts,id'],
            'customer' => ['required', 'array'],
            'customer.id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.doc_type' => ['required', 'string', 'max:50'],
            'customer.doc_number' => ['required', 'string', 'max:50'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $docType = strtoupper(trim((string) $data['customer']['doc_type']));
        $docNumber = strtoupper(trim((string) $data['customer']['doc_number']));
        $activeBranch = BranchInventory::activeBranch($businessId);

        if (filled($data['branch_id'] ?? null)) {
            $submittedBranchId = (int) $data['branch_id'];
            $branchBelongsToBusiness = Branch::query()
                ->where('business_id', $businessId)
                ->whereKey($submittedBranchId)
                ->exists();

            if (! $branchBelongsToBusiness || $submittedBranchId !== (int) $activeBranch->id) {
                throw ValidationException::withMessages([
                    'branch_id' => 'La sucursal de la venta no coincide con la sucursal activa.',
                ]);
            }
        }

        if ($docType === 'CF' || $docNumber === 'CF') {
            throw ValidationException::withMessages([
                'customer.doc_number' => 'Para crédito debes ingresar un NIT válido. CF no está permitido.',
            ]);
        }

        $idempotencyResult = app(IdempotencyService::class)->run(
            $businessId,
            $activeBranch->id,
            $request->user()->id,
            'credit_receipt_store',
            $data['idempotency_key'],
            $this->creditReceiptIdempotencyPayload($data, $businessId, $activeBranch->id, $request->user()->id),
            function () use ($request, $businessId, $data, $docType, $docNumber, $activeBranch) {
                $receipt = DB::transaction(function () use ($request, $businessId, $data, $docType, $docNumber, $activeBranch) {
            $branch = $activeBranch;
            $customer = $this->resolveCustomer($data['customer']);
            $shouldReserveStock = Credits::reserveStockOnCreditReservations($businessId);
            $subtotal = 0.0;
            $preparedLines = [];

            foreach ($data['items'] as $item) {
                $product = Product::query()
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->find($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'Uno o más productos no están disponibles.']);
                }

                BranchInventory::ensureProductInBranch($product, $branch->id);
                $quantity = (int) $item['quantity'];
                $available = StockAvailability::availableStock($product, null, $branch->id);

                if ($shouldReserveStock && ! StockPolicy::allowsNegativeStockForBusinessId($businessId) && $available < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => 'No hay suficiente stock disponible.',
                    ]);
                }

                $unitPrice = round((float) ($item['unit_price'] ?? $product->sale_price), 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;
                $preparedLines[] = compact('product', 'quantity', 'unitPrice', 'lineTotal');
            }

            $receipt = CreditReceipt::query()->create([
                'business_id' => $businessId,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_doc_type' => $docType,
                'customer_doc_number' => $docNumber,
                'customer_address' => $data['customer']['address'] ?? $customer->address,
                'receipt_number' => BusinessCounter::next($businessId, 'credit_receipts'),
                'status' => 'pending',
                'subtotal' => round($subtotal, 2),
                'discount_amount' => 0,
                'total' => round($subtotal, 2),
                'pending_total' => round($subtotal, 2),
                'notes' => $data['note'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($preparedLines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                CreditReceiptLine::query()->create([
                    'business_id' => $businessId,
                    'branch_id' => $branch->id,
                    'credit_receipt_id' => $receipt->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->code,
                    'quantity' => $line['quantity'],
                    'qty_reserved' => $shouldReserveStock ? $line['quantity'] : 0,
                    'qty_pending' => $line['quantity'],
                    'unit_price' => $line['unitPrice'],
                    'line_total' => $line['lineTotal'],
                    'pending_total' => $line['lineTotal'],
                    'status' => 'pending',
                ]);
            }

            return $receipt;
                });

                return [
                    'result_id' => $receipt->id,
                    'response_payload' => ['credit_receipt_id' => $receipt->id],
                ];
            },
            'credit_receipt',
        );
        $receipt = CreditReceipt::query()->findOrFail($idempotencyResult->resultId);

        $redirect = redirect()->route('sales.create')
            ->with('success', 'Crédito registrado correctamente.')
            ->with('credit_receipt_id', $receipt->id);

        if (! $idempotencyResult->replayed) {
            OperationDrafts::markConverted($data['draft_id'] ?? null, 'pos_sale', 'credit_receipt', $receipt->id, $request);
        }

        if (Permissions::userHas($request->user(), Permissions::CREDITS_PRINT)) {
            $redirect->with('credit_print_url', URL::temporarySignedRoute('credits.receipts.print', now()->addMinutes(10), ['creditReceipt' => $receipt->id]));
        }

        return $redirect;
    }

    public function print(CreditReceipt $creditReceipt)
    {
        abort_unless(Credits::reservationsEnabled(), 403, 'Las reservas de crédito no están habilitadas para este negocio.');
        $this->authorizeReceipt($creditReceipt);
        abort_unless(Permissions::userHas(request()->user(), Permissions::CREDITS_PRINT) || request()->hasValidSignature(), 403);

        $creditReceipt->load(['business.tenantSetting', 'branch', 'customer', 'lines.product']);
        $format = $creditReceipt->business->tenantSetting?->receipt_format === 'document' ? 'document' : 'ticket';

        return response()->view("credits.receipt-{$format}", [
            'receipt' => $creditReceipt,
            'logoUrl' => BusinessLogo::forPrint($creditReceipt->business, $creditReceipt->branch),
            'number' => Credits::formatNumber($creditReceipt),
            'previousPending' => $this->customerPendingBefore($creditReceipt),
            'newPending' => $this->customerPendingTotal($creditReceipt),
        ]);
    }

    public function cancelLine(Request $request, CreditReceiptLine $line): RedirectResponse
    {
        $this->ensureCreditsAvailable($request, Permissions::CREDITS_CANCEL_LINES);
        abort_unless((int) $line->business_id === (int) currentBusinessId(), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        app(IdempotencyService::class)->run(
            (int) $line->business_id,
            (int) $line->branch_id,
            $request->user()->id,
            'credit_receipt_line_cancel',
            $data['idempotency_key'],
            [
                'business_id' => (int) $line->business_id,
                'branch_id' => (int) $line->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'credit_receipt_line_cancel',
                'line_id' => $line->id,
                'reason' => $data['reason'],
            ],
            function () use ($line) {
                DB::transaction(function () use ($line) {
            $line = CreditReceiptLine::query()->lockForUpdate()->findOrFail($line->id);

            if ((int) $line->qty_pending <= 0) {
                throw ValidationException::withMessages([
                    'line' => 'Esta línea de crédito ya no está pendiente.',
                ]);
            }

            $line->update([
                'qty_cancelled' => (int) $line->qty_cancelled + (int) $line->qty_pending,
            ]);

            $line = Credits::refreshLine($line);
            Credits::refreshReceipt($line->receipt);
                });

                return [
                    'result_id' => $line->id,
                    'response_payload' => ['line_id' => $line->id],
                ];
            },
            'credit_receipt_line',
        );

        return back()->with('success', 'Línea de crédito cancelada.');
    }

    public function invoiceSelection(Request $request): RedirectResponse
    {
        $this->ensureCreditsAvailable($request, Permissions::CREDITS_INVOICE);

        $data = $request->validate([
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer', 'exists:credit_receipt_lines,id'],
        ]);

        $count = CreditReceiptLine::query()
            ->where('business_id', currentBusinessId())
            ->whereIn('id', $data['line_ids'])
            ->where('qty_pending', '>', 0)
            ->count();

        if ($count !== count(array_unique($data['line_ids']))) {
            throw ValidationException::withMessages(['line_ids' => 'Algunas líneas de crédito ya no están pendientes.']);
        }

        return redirect()->route('sales.create')
            ->with('success', 'Selección enviada al POS.')
            ->with('credit_invoice_line_ids', array_values(array_unique($data['line_ids'])));
    }

    public function resolveNit(Request $request): JsonResponse
    {
        $this->ensureCreditsAvailable($request, Permissions::CREDITS_TRANSFER_CUSTOMER);
        $business = Business::query()->findOrFail(currentBusinessId());

        try {
            $resolved = GuatemalaNitCustomerResolver::resolve($business, (string) $request->query('nit'), allowCache: false);
            /** @var Customer $customer */
            $customer = $resolved['customer'];

            return response()->json([
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'doc_type' => $customer->doc_type,
                    'doc_number' => $customer->doc_number,
                    'address' => $customer->address,
                    'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
                ],
                'source' => $resolved['source'],
            ]);
        } catch (ValidationException $exception) {
            $message = $exception->errors()['to_customer_doc_number'][0]
                ?? $exception->errors()['nit'][0]
                ?? GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE;

            return response()->json([
                'message' => $message,
                'errors' => ['nit' => [$message]],
            ], 422);
        }
    }

    public function transfer(Request $request, Customer $customer): RedirectResponse
    {
        $this->ensureCreditsAvailable($request, Permissions::CREDITS_TRANSFER_CUSTOMER);
        abort_unless((int) $customer->business_id === (int) currentBusinessId(), 403);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'to_customer_doc_number' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $business = Business::query()->findOrFail(currentBusinessId());
        $resolved = GuatemalaNitCustomerResolver::resolve($business, $data['to_customer_doc_number'], allowCache: false);
        /** @var Customer $to */
        $to = $resolved['customer'];

        app(IdempotencyService::class)->run(
            currentBusinessId(),
            BranchInventory::activeBranch(currentBusinessId())->id,
            $request->user()->id,
            'credit_customer_transfer',
            $data['idempotency_key'],
            [
                'business_id' => currentBusinessId(),
                'branch_id' => BranchInventory::activeBranch(currentBusinessId())->id,
                'user_id' => $request->user()->id,
                'operation_type' => 'credit_customer_transfer',
                'from_customer_id' => $customer->id,
                'to_customer_id' => $to->id,
                'to_customer_doc_number' => GuatemalaNitCustomerResolver::normalize($data['to_customer_doc_number']),
                'reason' => $data['reason'],
            ],
            function () use ($request, $customer, $to, $data, $resolved) {
                DB::transaction(function () use ($request, $customer, $to, $data, $resolved) {
            $receipts = CreditReceipt::query()
                ->where('business_id', currentBusinessId())
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'partially_invoiced'])
                ->lockForUpdate()
                ->get();

            if ($receipts->isEmpty()) {
                throw ValidationException::withMessages([
                    'customer' => 'No hay reservas pendientes para transferir.',
                ]);
            }

            CreditReceipt::query()
                ->whereKey($receipts->pluck('id'))
                ->update([
                    'customer_id' => $to->id,
                    'customer_name' => $to->name,
                    'customer_doc_type' => $to->doc_type,
                    'customer_doc_number' => $to->doc_number,
                    'customer_address' => $to->address,
                ]);

            CreditCustomerTransfer::query()->create([
                'business_id' => currentBusinessId(),
                'from_customer_id' => $customer->id,
                'to_customer_id' => $to->id,
                'transferred_by' => $request->user()?->id,
                'reason' => $data['reason'],
                'metadata' => [
                    'receipt_ids' => $receipts->pluck('id')->all(),
                    'pending_total' => round((float) $receipts->sum('pending_total'), 2),
                    'target_nit_entered' => GuatemalaNitCustomerResolver::normalize($data['to_customer_doc_number']),
                    'target_customer_source' => $resolved['source'],
                    'target_customer_name' => $to->name,
                    'resolved_at' => now()->toIso8601String(),
                ],
            ]);
                });

                return [
                    'result_id' => $to->id,
                    'response_payload' => ['customer_id' => $to->id],
                ];
            },
            'credit_customer_transfer',
        );

        return redirect()->route('credits.customers.show', $to)->with('success', 'Deuda transferida.');
    }

    private function ensureCreditsAvailable(Request $request, string $permission): void
    {
        if (! Credits::reservationsEnabled()) {
            throw ValidationException::withMessages([
                'document_type' => 'Las reservas de crédito no están habilitadas para este negocio.',
            ]);
        }

        abort_unless(Permissions::userHas($request->user(), $permission), 403);
    }

    private function authorizeReceipt(CreditReceipt $receipt): void
    {
        abort_unless((int) $receipt->business_id === (int) currentBusinessId(), 403);
    }

    private function creditReceiptIdempotencyPayload(array $data, int $businessId, int $branchId, int $userId): array
    {
        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'operation_type' => 'credit_receipt_store',
            'customer' => $data['customer'] ?? null,
            'note' => $data['note'] ?? null,
            'items' => collect($data['items'] ?? [])
                ->map(fn (array $item) => [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price' => isset($item['unit_price']) ? round((float) $item['unit_price'], 4) : null,
                ])
                ->sortBy(fn (array $item) => implode('|', [$item['product_id'], $item['quantity'], $item['unit_price'] ?? '']))
                ->values()
                ->all(),
        ];
    }

    private function resolveCustomer(array $data): Customer
    {
        $businessId = currentBusinessId();
        $docNumber = strtoupper(trim((string) $data['doc_number']));
        $customer = isset($data['id'])
            ? Customer::query()->where('business_id', $businessId)->find($data['id'])
            : null;

        $customer ??= Customer::query()
            ->where('business_id', $businessId)
            ->whereRaw('UPPER(doc_number) = ?', [$docNumber])
            ->first();

        $payload = [
            'business_id' => $businessId,
            'name' => $data['name'],
            'doc_type' => strtoupper(trim((string) $data['doc_type'])),
            'doc_number' => $docNumber,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'country' => 'GT',
            'is_final_consumer' => false,
        ];

        if ($customer) {
            $customer->update($payload);

            return $customer->refresh();
        }

        return Customer::query()->create($payload);
    }

    private function customerPendingBefore(CreditReceipt $receipt): float
    {
        return round((float) CreditReceipt::query()
            ->where('business_id', $receipt->business_id)
            ->where('customer_id', $receipt->customer_id)
            ->where('id', '!=', $receipt->id)
            ->sum('pending_total'), 2);
    }

    private function customerPendingTotal(CreditReceipt $receipt): float
    {
        return round($this->customerPendingBefore($receipt) + (float) $receipt->pending_total, 2);
    }
}
