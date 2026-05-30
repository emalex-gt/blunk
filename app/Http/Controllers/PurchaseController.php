<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\CashRegister;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\ProductSupplierCostHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Purchases/Index', [
            'purchases' => Purchase::query()
                ->where('business_id', currentBusinessId())
                ->when(BranchInventory::branchesEnabled(currentBusinessId()), fn ($query) => $query->where('branch_id', BranchInventory::activeBranch(currentBusinessId())->id))
                ->with(['supplier:id,name', 'createdBy:id,name'])
                ->latest()
                ->paginate(25)
                ->withQueryString(),
        ]);
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
