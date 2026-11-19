<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\BranchAccessService;
use App\Support\CashRegister;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\DocumentCompanyHeader;
use App\Support\Exports\TableExporter;
use App\Support\IdempotencyService;
use App\Support\Inventory\ProductQuantityPolicy;
use App\Support\Inventory\StockPolicy;
use App\Support\OperationDrafts;
use App\Support\Permissions;
use App\Support\ProductSupplierCostHistory;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $range = ReportDateRange::monthToDate($request, $business);

        return Inertia::render('Purchases/Index', [
            'purchases' => $this->purchaseListQuery($request, $range)
                ->with(['supplier:id,name', 'createdBy:id,name', 'branch:id,name'])
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'filters' => $this->purchaseFilters($request, $range),
            'timezone' => tenantTimezone($business),
        ]);
    }

    public function export(Request $request, string $format): SymfonyResponse
    {
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);
        abort_unless(Permissions::userHas($request->user(), Permissions::PURCHASES_EXPORT), 403);

        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'name', 'country', 'phone')->find($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $query = $this->purchaseListQuery($request, $range)
            ->with(['supplier:id,name', 'createdBy:id,name', 'branch:id,name'])
            ->withSum('items as products_subtotal', 'total')
            ->latest();
        $count = (clone $query)->count();

        if ($count > 5000) {
            throw ValidationException::withMessages([
                'export' => 'La exportación es demasiado grande. Reduce los filtros e inténtalo nuevamente.',
            ]);
        }

        $exportTotal = (float) (clone $query)->sum('total');
        $exportTransport = (float) (clone $query)->sum('transport_cost');

        $rows = $query->limit(5000)->get()->map(fn (Purchase $purchase) => [
            $purchase->created_at?->timezone(tenantTimezone($business))->format('Y-m-d H:i'),
            format_purchase_number($purchase),
            $purchase->supplier_invoice_number ?: '-',
            $purchase->supplier?->name ?? 'Sin proveedor',
            $this->paymentMethodLabel($purchase->payment_method),
            $purchase->paid_from_cash ? 'Sí' : 'No',
            $purchase->status ?? 'completed',
            (float) ($purchase->products_subtotal ?? 0),
            (float) $purchase->transport_cost,
            (float) $purchase->total,
            $purchase->createdBy?->name ?? '-',
            $purchase->branch?->name ?? '-',
        ])->all();

        return TableExporter::download([
            'title' => 'Reporte de compras',
            'businessName' => $business?->name ?? 'Empresa',
            'branchName' => BranchInventory::activeBranch($businessId)->name,
            'generatedAt' => now(tenantTimezone($business))->format('Y-m-d H:i'),
            'filters' => TableExporter::filters($this->purchaseFilters($request, $range)),
            'columns' => ['Fecha', 'No. compra', 'Factura proveedor', 'Proveedor', 'Forma de pago', 'Pagado desde caja', 'Estado', 'Subtotal productos', 'Transporte', 'Total', 'Usuario', 'Sucursal'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total compras', 'value' => $count],
                ['label' => 'Subtotal productos', 'value' => $exportTotal - $exportTransport],
                ['label' => 'Transporte', 'value' => $exportTransport],
                ['label' => 'Total', 'value' => $exportTotal],
            ],
        ], $format, 'compras');
    }

    public function create(Request $request): Response
    {
        $businessId = currentBusinessId();
        $branchesEnabled = BranchInventory::branchesEnabled($businessId);
        $activeBranch = BranchInventory::activeBranch($businessId);

        return Inertia::render('Purchases/Create', [
            'products' => [],
            'suppliers' => Supplier::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(30)
                ->get(['id', 'name', 'phone', 'email', 'address', 'contact_person']),
            'hasOpenCashRegister' => CashRegister::currentOpenSession($businessId) !== null,
            'branches_enabled' => $branchesEnabled,
            'branches' => $branchesEnabled
                ? app(BranchAccessService::class)->authorizedBranches($request->user(), $businessId)
                : [],
            'active_branch' => $branchesEnabled ? $activeBranch : null,
        ]);
    }

    public function productSearch(Request $request): JsonResponse
    {
        $businessId = currentBusinessId();
        $activeBranch = BranchInventory::activeBranch($businessId);
        $search = trim((string) $request->query('q', ''));
        $ids = $this->productIdsFromRequest($request);
        $limit = min(max($request->integer('limit', 20), 1), 30);

        if ($search === '' && $ids === []) {
            return response()->json(['products' => []]);
        }

        $products = $this->purchaseProductQuery($businessId, $activeBranch->id, $search, $ids, $limit)
            ->get(['id', 'business_id', 'category_id', 'brand_id', 'name', 'code', 'barcode', 'cost_price', 'sale_price', 'stock', 'min_stock', 'allow_decimal_quantity', 'location', 'image_url']);

        return response()->json([
            'products' => $this->purchaseProductPayload($products, $businessId, $activeBranch->id),
        ]);
    }

    public function supplierSearch(Request $request): JsonResponse
    {
        $businessId = currentBusinessId();
        $search = trim((string) $request->query('q', ''));
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->take(30)
            ->values()
            ->all();

        if ($search === '' && $ids === []) {
            return response()->json(['suppliers' => []]);
        }

        $suppliers = Supplier::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($query) use ($search, $ids) {
                if ($ids !== []) {
                    $query->whereIn('id', $ids);
                }

                if ($search !== '') {
                    $query->orWhere(function ($query) use ($search) {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
                }
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'email', 'address', 'contact_person']);

        return response()->json(['suppliers' => $suppliers]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'array'],
            'supplier.name' => ['nullable', 'string', 'max:255'],
            'supplier.address' => ['nullable', 'string', 'max:255'],
            'supplier.email' => ['nullable', 'email', 'max:255'],
            'supplier.phone' => ['nullable', 'string', 'max:50'],
            'supplier.contact_person' => ['nullable', 'string', 'max:255'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'transport_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'payment_method' => ['required', 'string', 'in:cash,card,bank_transfer,deposit,check,credit,other'],
            'paid_from_cash' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'draft_id' => ['nullable', 'integer', 'exists:operation_drafts,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,5', 'min:0'],
        ], [
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.quantity.decimal' => 'La cantidad debe tener como máximo cuatro decimales.',
            'items.*.quantity.gt' => 'La cantidad debe ser mayor a cero.',
        ]);

        $businessId = currentBusinessId();
        $branch = isset($data['branch_id'])
                ? \App\Models\Branch::query()
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->findOrFail((int) $data['branch_id'])
                : BranchInventory::activeBranch($businessId);

        $idempotencyResult = app(IdempotencyService::class)->run(
            $businessId,
            $branch->id,
            $request->user()->id,
            'purchase_store',
            $data['idempotency_key'],
            $this->purchaseIdempotencyPayload($data, $businessId, $branch->id, $request->user()->id),
            function () use ($request, $data, $businessId, $branch) {
                $purchase = DB::transaction(function () use ($request, $data, $businessId, $branch) {

            if (BranchInventory::branchesEnabled($businessId)) {
                app(BranchAccessService::class)->assertCanAccessBranch($request->user(), $branch, $businessId);
            }
            $paymentMethod = $data['payment_method'];
            $paidFromCash = $paymentMethod === 'cash' && (bool) ($data['paid_from_cash'] ?? false);
            $cashSession = $paidFromCash
                ? CashRegister::requireOpenSession(
                    $businessId,
                    'Debes abrir caja antes de pagar compras desde caja.',
                    true,
                    $branch->id,
                )
                : CashRegister::currentOpenSession($businessId, true, $branch->id);
            $supplier = $this->resolveSupplier(
                $businessId,
                $data['supplier_id'] ?? null,
                $data['supplier_name'] ?? null,
                $data['supplier'] ?? null,
            );
            $supplierInvoiceNumber = trim((string) ($data['supplier_invoice_number'] ?? ''));
            $purchase = Purchase::create([
                'business_id' => $businessId,
                'business_number' => BusinessCounter::next($businessId, 'purchases'),
                'branch_id' => $branch->id,
                'supplier_id' => $supplier?->id,
                'supplier_invoice_number' => $supplierInvoiceNumber !== '' ? $supplierInvoiceNumber : null,
                'status' => 'completed',
                'total' => 0,
                'transport_cost' => $data['transport_cost'] ?? 0,
                'note' => $data['note'] ?? null,
                'payment_method' => $paymentMethod,
                'paid_from_cash' => $paidFromCash,
                'cash_register_session_id' => $cashSession?->id,
                'created_by' => $request->user()->id,
            ]);

            $total = '0';

            foreach ($data['items'] as $item) {
                $product = Product::query()
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->find($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno o más productos no pertenecen a este negocio.',
                    ]);
                }
                BranchInventory::ensureProductInBranch($product, $branch->id);

                $incomingQty = ProductQuantityPolicy::positive($product, $item['quantity']);
                $incomingCost = $this->decimal($item['unit_cost'], 5);
                $currentStock = ProductQuantityPolicy::signed($product->stock);
                $currentCost = $this->decimal($product->cost_price, 5);
                [$currentBranchStock, $newBranchStock] = BranchInventory::increase($product, $branch->id, $incomingQty);
                $newStock = ProductQuantityPolicy::signed(Product::query()->whereKey($product->id)->value('stock'));
                $newAverageCost = ProductQuantityPolicy::compare($currentStock, '0') <= 0
                    ? $incomingCost
                    : $this->roundDecimal(
                        bcdiv(
                            bcadd(
                                bcmul($currentStock, $currentCost, 9),
                                bcmul($incomingQty, $incomingCost, 9),
                                9,
                            ),
                            $newStock,
                            9,
                        ),
                        5,
                    );
                $lineTotal = $this->roundDecimal(bcmul($incomingQty, $incomingCost, 9), 5);
                $total = bcadd($total, $lineTotal, 5);

                $purchase->items()->create([
                    'business_id' => $businessId,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $incomingQty,
                    'unit_cost' => $incomingCost,
                    'previous_cost' => $currentCost,
                    'new_average_cost' => $newAverageCost,
                    'total' => $lineTotal,
                ]);

                $product->update([
                    'cost_price' => $newAverageCost,
                ]);

                StockMovement::create([
                    'business_id' => $businessId,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'type' => 'purchase',
                    'quantity' => $incomingQty,
                    'previous_stock' => $currentBranchStock,
                    'new_stock' => $newBranchStock,
                    'note' => stockMovementNote('purchase', $purchase->business_number ?: $purchase->id),
                    'created_by' => $request->user()->id,
                    'user_id' => $request->user()->id,
                ]);
            }

            $purchaseTotal = $this->roundDecimal(bcadd($total, $this->decimal($data['transport_cost'] ?? 0, 2), 5), 5);
            $purchase->update(['total' => $purchaseTotal]);

            if ($paidFromCash && $cashSession && bccomp($purchaseTotal, '0', 5) > 0) {
                $cashPurchaseAmount = (float) $this->roundDecimal($purchaseTotal, 2);
                CashRegister::recordMovement(
                    $cashSession,
                    'purchase_cash',
                    -1 * $cashPurchaseAmount,
                    'purchase',
                    $purchase->id,
                    stockMovementNote('purchase', $purchase->business_number ?: $purchase->id),
                    $request->user()->id,
                );
            }

            return $purchase;
                });

                return [
                    'result_id' => $purchase->id,
                    'response_payload' => ['purchase_id' => $purchase->id],
                ];
            },
            'purchase',
        );

        if (! $idempotencyResult->replayed) {
            OperationDrafts::markConverted($data['draft_id'] ?? null, 'purchase', 'purchase', $idempotencyResult->resultId, $request);
        }

        return redirect()
            ->route('purchases.index')
            ->with('success', 'Compra registrada correctamente');
    }

    public function show(Request $request, Purchase $purchase): Response
    {
        abort_unless($purchase->business_id === currentBusinessId(), 403);
        $this->authorizePurchaseBranch($request, $purchase);

        return Inertia::render('Purchases/Show', [
            'purchase' => $purchase->load([
                'supplier:id,name',
                'branch:id,name',
                'createdBy:id,name',
                'cancelledBy:id,name',
                'cashRegisterSession:id',
                'items.product:id,code,barcode',
            ]),
            'canCancel' => $purchase->status === 'completed'
                && Permissions::userHas($request->user(), Permissions::PURCHASES_CANCEL),
        ]);
    }

    public function cancel(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_unless(Permissions::userHas($request->user(), Permissions::PURCHASES_CANCEL), 403);
        abort_unless((int) $purchase->business_id === (int) currentBusinessId(), 403);
        $this->authorizePurchaseBranch($request, $purchase);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ], ['reason.required' => 'Debes indicar el motivo de la anulación.']);

        DB::transaction(function () use ($purchase, $request, $data) {
            $lockedPurchase = Purchase::query()
                ->where('business_id', currentBusinessId())
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($purchase->id);

            if ($lockedPurchase->status !== 'completed' || $lockedPurchase->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'purchase' => 'La compra ya fue anulada o no puede anularse en su estado actual.',
                ]);
            }

            foreach ($lockedPurchase->items as $item) {
                $product = Product::query()
                    ->where('business_id', $lockedPurchase->business_id)
                    ->lockForUpdate()
                    ->find($item->product_id);

                if (! $product) {
                    throw ValidationException::withMessages(['purchase' => 'No se puede anular: uno de los productos originales ya no existe.']);
                }

                $quantity = ProductQuantityPolicy::positive($product, $item->quantity);
                StockPolicy::assertCanDecreaseStock(
                    (int) $lockedPurchase->business_id,
                    (int) $lockedPurchase->branch_id,
                    $product,
                    null,
                    $quantity,
                    'purchase_cancellation',
                );

                [$previousStock, $newStock] = BranchInventory::decrease($product, (int) $lockedPurchase->branch_id, $quantity);
                StockMovement::query()->create([
                    'business_id' => $lockedPurchase->business_id,
                    'branch_id' => $lockedPurchase->branch_id,
                    'product_id' => $product->id,
                    'type' => 'purchase_cancellation',
                    'quantity' => ProductQuantityPolicy::signed('-'.$quantity),
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'note' => 'Anulación de '.stockMovementNote('purchase', $lockedPurchase->business_number ?: $lockedPurchase->id),
                    'created_by' => $request->user()->id,
                    'user_id' => $request->user()->id,
                ]);
            }

            $lockedPurchase->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => trim($data['reason']),
            ]);
        });

        return back()->with('success', 'Compra anulada e inventario revertido correctamente.');
    }

    public function print(Request $request, Purchase $purchase): View
    {
        abort_unless($purchase->business_id === currentBusinessId(), 403);
        $this->authorizePurchaseBranch($request, $purchase);

        $purchase->load([
            'business.tenantSetting',
            'supplier:id,name,phone,email,address,contact_person',
            'branch:id,business_id,name,address,municipality,department,phone,logo_url',
            'createdBy:id,name',
            'items.product:id,code,barcode',
        ]);

        return view('purchases.print', [
            'purchase' => $purchase,
            'business' => $purchase->business,
            'tenantSetting' => $purchase->business?->tenantSetting,
            'company' => DocumentCompanyHeader::make($purchase->business, $purchase->branch, $purchase->business?->tenantSetting),
            'purchaseNumber' => format_purchase_number($purchase),
            'timezone' => tenantTimezone($purchase->business),
        ]);
    }

    private function purchaseListQuery(Request $request, ReportDateRange $range)
    {
        $businessId = currentBusinessId();
        $supplierSearch = trim((string) $request->query('supplier_search', ''));
        $purchaseNumber = trim((string) $request->query('purchase_number', ''));
        $supplierInvoiceNumber = trim((string) $request->query('supplier_invoice_number', ''));
        $paymentMethod = (string) $request->query('payment_method', 'all');
        $paidFromCash = (string) $request->query('paid_from_cash_register', 'all');
        $status = (string) $request->query('status', 'all');
        $productSearch = trim((string) $request->query('product_search', ''));

        return Purchase::query()
            ->where('business_id', $businessId)
            ->where('branch_id', BranchInventory::activeBranch($businessId)->id)
            ->whereBetween('created_at', [$range->start, $range->end])
            ->when($supplierSearch !== '', function ($query) use ($supplierSearch) {
                $query->whereHas('supplier', function ($query) use ($supplierSearch) {
                    $query->where('name', 'ilike', "%{$supplierSearch}%")
                        ->orWhere('phone', 'ilike', "%{$supplierSearch}%")
                        ->orWhere('email', 'ilike', "%{$supplierSearch}%");
                });
            })
            ->when($purchaseNumber !== '', function ($query) use ($purchaseNumber) {
                $number = preg_replace('/\D+/', '', $purchaseNumber);
                $query->when($number !== '', fn ($query) => $query->where(function ($query) use ($number) {
                    $query->where('business_number', (int) $number)
                        ->orWhere('id', (int) $number);
                }));
            })
            ->when($supplierInvoiceNumber !== '', fn ($query) => $query->where('supplier_invoice_number', 'ilike', "%{$supplierInvoiceNumber}%"))
            ->when(in_array($paymentMethod, ['cash', 'card', 'bank_transfer', 'deposit', 'check', 'credit', 'other'], true), fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($paidFromCash === 'yes', fn ($query) => $query->where('paid_from_cash', true))
            ->when($paidFromCash === 'no', fn ($query) => $query->where('paid_from_cash', false))
            ->when(in_array($status, ['pending', 'completed', 'cancelled'], true), fn ($query) => $query->where('status', $status))
            ->when($productSearch !== '', function ($query) use ($productSearch) {
                $query->whereHas('items', function ($query) use ($productSearch) {
                    $query->where('product_name', 'ilike', "%{$productSearch}%")
                        ->orWhereHas('product', function ($query) use ($productSearch) {
                            $query->where('name', 'ilike', "%{$productSearch}%")
                                ->orWhere('code', 'ilike', "%{$productSearch}%")
                                ->orWhere('barcode', 'ilike', "%{$productSearch}%");
                        });
                });
            });
    }

    private function purchaseIdempotencyPayload(array $data, int $businessId, int $branchId, int $userId): array
    {
        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'operation_type' => 'purchase_store',
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_name' => $data['supplier_name'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'paid_from_cash' => (bool) ($data['paid_from_cash'] ?? false),
            'transport_cost' => round((float) ($data['transport_cost'] ?? 0), 2),
            'note' => $data['note'] ?? null,
            'items' => collect($data['items'] ?? [])
                ->map(fn (array $item) => [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'quantity' => ProductQuantityPolicy::canonicalForIdempotency($item['quantity'] ?? 0),
                    'unit_cost' => round((float) ($item['unit_cost'] ?? 0), 5),
                ])
                ->sortBy(fn (array $item) => implode('|', [$item['product_id'], $item['quantity'], $item['unit_cost']]))
                ->values()
                ->all(),
        ];
    }

    private function purchaseFilters(Request $request, ReportDateRange $range): array
    {
        return [
            'date_from' => $range->dateFrom,
            'date_to' => $range->dateTo,
            'supplier_search' => trim((string) $request->query('supplier_search', '')),
            'purchase_number' => trim((string) $request->query('purchase_number', '')),
            'supplier_invoice_number' => trim((string) $request->query('supplier_invoice_number', '')),
            'payment_method' => (string) $request->query('payment_method', 'all'),
            'paid_from_cash_register' => (string) $request->query('paid_from_cash_register', 'all'),
            'status' => (string) $request->query('status', 'all'),
            'product_search' => trim((string) $request->query('product_search', '')),
        ];
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'bank_transfer' => 'Transferencia',
            'deposit' => 'Depósito',
            'check' => 'Cheque',
            'credit' => 'Crédito',
            'other' => 'Otro',
            default => $method ?: '-',
        };
    }

    private function productIdsFromRequest(Request $request): array
    {
        $ids = $request->input('product_ids', $request->query('ids', ''));

        if (is_array($ids)) {
            return collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->take(50)->values()->all();
        }

        return collect(explode(',', (string) $ids))->map(fn (string $id) => (int) trim($id))->filter()->unique()->take(50)->values()->all();
    }

    private function purchaseProductQuery(int $businessId, int $branchId, string $search, array $ids, int $limit)
    {
        $query = Product::query()
            ->where('products.business_id', $businessId)
            ->where('products.is_active', true)
            ->with([
                'category:id,business_id,name',
                'brand:id,business_id,name',
            ]);

        BranchInventory::restrictProductsToBranch($query, $businessId, $branchId);

        if ($ids !== []) {
            return $query->whereIn('products.id', $ids)->orderBy('products.name');
        }

        $like = "%{$search}%";
        $normalized = $this->normalizeProductSearchTerm($search);

        return $query
            ->where(function ($query) use ($businessId, $like, $normalized) {
                $query->whereRaw($this->normalizedSql('products.code').' = ?', [$normalized])
                    ->orWhereRaw($this->normalizedSql('products.barcode').' = ?', [$normalized])
                    ->orWhere('products.code', 'ilike', $like)
                    ->orWhere('products.barcode', 'ilike', $like)
                    ->orWhere('products.name', 'ilike', $like)
                    ->orWhereHas('category', fn ($category) => $category->where('business_id', $businessId)->where('name', 'ilike', $like))
                    ->orWhereHas('brand', fn ($brand) => $brand->where('business_id', $businessId)->where('name', 'ilike', $like));
            })
            ->orderByRaw(
                'CASE WHEN '.$this->normalizedSql('products.code').' = ? OR '.$this->normalizedSql('products.barcode').' = ? THEN 0 ELSE 1 END',
                [$normalized, $normalized],
            )
            ->orderBy('products.name')
            ->limit($limit);
    }

    private function purchaseProductPayload($products, int $businessId, int $branchId): array
    {
        BranchInventory::applyBranchStockAndPrices($products, $businessId, $branchId);
        $supplierCosts = ProductSupplierCostHistory::forProducts($businessId, $products->pluck('id')->all());

        return $products->map(function (Product $product) use ($supplierCosts) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'category_name' => $product->category?->name,
                'brand_name' => $product->brand?->name,
                'cost_price' => (string) $product->cost_price,
                'sale_price' => (string) $product->sale_price,
                'stock' => (string) $product->stock,
                'min_stock' => (string) $product->min_stock,
                'allow_decimal_quantity' => (bool) $product->allow_decimal_quantity,
                'location' => $product->location,
                'image_url' => $product->image_url,
                'supplier_costs' => $supplierCosts->get($product->id, collect())->values(),
            ];
        })->values()->all();
    }

    private function decimal(mixed $value, int $scale): string
    {
        return bcadd((string) $value, '0', $scale);
    }

    private function roundDecimal(string $value, int $scale): string
    {
        $halfUnit = '0.'.str_repeat('0', $scale).'5';
        $offset = str_starts_with($value, '-') ? '-'.$halfUnit : $halfUnit;

        return bcadd(bcadd($value, $offset, $scale + 1), '0', $scale);
    }

    private function normalizeProductSearchTerm(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($value)) ?? '');
    }

    private function normalizedSql(string $column): string
    {
        return "UPPER(regexp_replace(TRIM(COALESCE({$column}, '')), '\\s+', ' ', 'g'))";
    }

    private function authorizePurchaseBranch(Request $request, Purchase $purchase): void
    {
        $businessId = currentBusinessId();

        if (BranchInventory::branchesEnabled($businessId) && $purchase->branch_id) {
            app(BranchAccessService::class)->assertCanAccessBranch($request->user(), (int) $purchase->branch_id, $businessId);
        }
    }

    private function resolveSupplier(int $businessId, mixed $supplierId, ?string $supplierName, ?array $supplierData): ?Supplier
    {
        if ($supplierId) {
            $supplier = Supplier::query()
                ->where('business_id', $businessId)
                ->find($supplierId);

            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'El proveedor seleccionado no pertenece a este negocio.',
                ]);
            }

            return $supplier;
        }

        $supplierName = trim((string) ($supplierData['name'] ?? $supplierName));

        if ($supplierName === '') {
            return null;
        }

        $supplier = Supplier::query()
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($supplierName)])
            ->first();

        if ($supplier) {
            return $supplier;
        }

        return Supplier::create([
            'business_id' => $businessId,
            'name' => $supplierName,
            'address' => filled($supplierData['address'] ?? null) ? trim((string) $supplierData['address']) : null,
            'email' => filled($supplierData['email'] ?? null) ? trim((string) $supplierData['email']) : null,
            'phone' => filled($supplierData['phone'] ?? null) ? trim((string) $supplierData['phone']) : null,
            'contact_person' => filled($supplierData['contact_person'] ?? null) ? trim((string) $supplierData['contact_person']) : null,
            'is_active' => true,
        ]);
    }
}
