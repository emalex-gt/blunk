<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\CashRegister;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\Exports\TableExporter;
use App\Support\Permissions;
use App\Support\ProductSupplierCostHistory;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
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
        ]);
    }

    public function export(Request $request, string $format): SymfonyResponse
    {
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);
        abort_unless(Permissions::userHas($request->user(), Permissions::PURCHASES_EXPORT), 403);

        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'name', 'country')->find($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $query = $this->purchaseListQuery($request, $range)
            ->with(['supplier:id,name', 'createdBy:id,name', 'branch:id,name'])
            ->latest();
        $count = (clone $query)->count();

        if ($count > 5000) {
            throw ValidationException::withMessages([
                'export' => 'La exportación es demasiado grande. Reduce los filtros e inténtalo nuevamente.',
            ]);
        }

        $rows = $query->limit(5000)->get()->map(fn (Purchase $purchase) => [
            $purchase->created_at?->timezone(tenantTimezone($business))->format('Y-m-d H:i'),
            format_purchase_number($purchase),
            $purchase->supplier?->name ?? 'Sin proveedor',
            $this->paymentMethodLabel($purchase->payment_method),
            $purchase->paid_from_cash ? 'Sí' : 'No',
            $purchase->status ?? 'completed',
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
            'columns' => ['Fecha', 'No. compra', 'Proveedor', 'Forma de pago', 'Pagado desde caja', 'Estado', 'Total', 'Usuario', 'Sucursal'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total compras', 'value' => $count],
                ['label' => 'Total', 'value' => (float) (clone $query)->sum('total')],
            ],
        ], $format, 'compras');
    }

    public function create(Request $request): Response
    {
        $businessId = currentBusinessId();
        $branchesEnabled = BranchInventory::branchesEnabled($businessId);
        $activeBranch = BranchInventory::activeBranch($businessId);
        $productsQuery = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true);
        BranchInventory::restrictProductsToBranch($productsQuery, $businessId, $activeBranch->id);
        $products = $productsQuery
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'barcode', 'cost_price', 'stock', 'min_stock', 'location', 'image_url']);
        BranchInventory::applyBranchStockAndPrices($products, $businessId, $activeBranch->id);
        $supplierCosts = ProductSupplierCostHistory::forProducts($businessId, $products->pluck('id')->all());

        $products->each(function (Product $product) use ($supplierCosts) {
            $product->setAttribute(
                'supplier_costs',
                $supplierCosts->get($product->id, collect())->values(),
            );
        });

        return Inertia::render('Purchases/Create', [
            'products' => $products,
            'suppliers' => Supplier::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'email', 'address', 'contact_person']),
            'hasOpenCashRegister' => CashRegister::currentOpenSession($businessId) !== null,
            'branches_enabled' => $branchesEnabled,
            'branches' => $branchesEnabled ? BranchInventory::branchOptions($businessId) : [],
            'active_branch' => $branchesEnabled ? $activeBranch : null,
        ]);
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
            'payment_method' => ['required', 'string', 'in:cash,card,bank_transfer,check,credit,other'],
            'paid_from_cash' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ], [
            'items.*.quantity.required' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad debe ser un número entero.',
        ]);

        DB::transaction(function () use ($request, $data) {
            $businessId = currentBusinessId();
            $branch = isset($data['branch_id'])
                ? \App\Models\Branch::query()
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->findOrFail((int) $data['branch_id'])
                : BranchInventory::activeBranch($businessId);

            if (! BranchInventory::canSwitchBranches($request->user()) && (int) $branch->id !== (int) BranchInventory::activeBranch($businessId)->id) {
                throw ValidationException::withMessages([
                    'branch_id' => 'No tienes permiso para comprar en otra sucursal.',
                ]);
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
                : null;
            $supplier = $this->resolveSupplier(
                $businessId,
                $data['supplier_id'] ?? null,
                $data['supplier_name'] ?? null,
                $data['supplier'] ?? null,
            );
            $purchase = Purchase::create([
                'business_id' => $businessId,
                'business_number' => BusinessCounter::next($businessId, 'purchases'),
                'branch_id' => $branch->id,
                'supplier_id' => $supplier?->id,
                'status' => 'completed',
                'total' => 0,
                'note' => $data['note'] ?? null,
                'payment_method' => $paymentMethod,
                'paid_from_cash' => $paidFromCash,
                'cash_register_session_id' => $cashSession?->id,
                'created_by' => $request->user()->id,
            ]);

            $total = 0;

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

                $incomingQty = (int) $item['quantity'];
                $incomingCost = (float) $item['unit_cost'];
                $currentStock = (float) $product->stock;
                $currentCost = (float) $product->cost_price;
                [$currentBranchStock, $newBranchStock] = BranchInventory::increase($product, $branch->id, $incomingQty);
                $newStock = (float) Product::query()->whereKey($product->id)->value('stock');
                $newAverageCost = $currentStock <= 0
                    ? $incomingCost
                    : (($currentStock * $currentCost) + ($incomingQty * $incomingCost)) / $newStock;
                $lineTotal = round($incomingQty * $incomingCost, 2);
                $total += $lineTotal;

                $purchase->items()->create([
                    'business_id' => $businessId,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $incomingQty,
                    'unit_cost' => $incomingCost,
                    'previous_cost' => $currentCost,
                    'new_average_cost' => round($newAverageCost, 2),
                    'total' => $lineTotal,
                ]);

                $product->update([
                    'cost_price' => round($newAverageCost, 2),
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

            $purchase->update(['total' => round($total, 2)]);

            if ($cashSession && $total > 0) {
                CashRegister::recordMovement(
                    $cashSession,
                    'purchase_cash',
                    -1 * $total,
                    'purchase',
                    $purchase->id,
                    stockMovementNote('purchase', $purchase->business_number ?: $purchase->id),
                    $request->user()->id,
                );
            }
        });

        return redirect()
            ->route('purchases.index')
            ->with('success', 'Compra registrada correctamente');
    }

    public function show(Request $request, Purchase $purchase): Response
    {
        abort_unless($purchase->business_id === currentBusinessId(), 403);
        abort_unless(
            ! BranchInventory::branchesEnabled(currentBusinessId())
            || BranchInventory::canSwitchBranches($request->user())
            || (int) $purchase->branch_id === (int) BranchInventory::activeBranch(currentBusinessId())->id,
            403,
        );

        return Inertia::render('Purchases/Show', [
            'purchase' => $purchase->load([
                'supplier:id,name',
                'branch:id,name',
                'createdBy:id,name',
                'cashRegisterSession:id',
                'items.product:id,code,barcode',
            ]),
        ]);
    }

    private function purchaseListQuery(Request $request, ReportDateRange $range)
    {
        $businessId = currentBusinessId();
        $supplierSearch = trim((string) $request->query('supplier_search', ''));
        $purchaseNumber = trim((string) $request->query('purchase_number', ''));
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
            ->when(in_array($paymentMethod, ['cash', 'card', 'bank_transfer', 'check', 'credit', 'other'], true), fn ($query) => $query->where('payment_method', $paymentMethod))
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

    private function purchaseFilters(Request $request, ReportDateRange $range): array
    {
        return [
            'date_from' => $range->dateFrom,
            'date_to' => $range->dateTo,
            'supplier_search' => trim((string) $request->query('supplier_search', '')),
            'purchase_number' => trim((string) $request->query('purchase_number', '')),
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
            'check' => 'Cheque',
            'credit' => 'Crédito',
            'other' => 'Otro',
            default => $method ?: '-',
        };
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
