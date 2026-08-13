<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\BranchInventory;
use App\Support\Inventory\StockPolicy;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function quick(Request $request): Response
    {
        $businessId = currentBusinessId();
        $activeBranch = BranchInventory::activeBranch($businessId);
        $products = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'barcode', 'stock', 'min_stock', 'location', 'image_url']);
        BranchInventory::applyBranchStockAndPrices($products, $businessId, $activeBranch->id);
        $this->applyReservedStock($products, $activeBranch->id);

        return Inertia::render('Stock/Quick', [
            'products' => $products,
            'branches_enabled' => BranchInventory::branchesEnabled($businessId),
            'active_branch' => BranchInventory::branchesEnabled($businessId) ? $activeBranch : null,
        ]);
    }

    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $activeBranch = BranchInventory::activeBranch($businessId);

        return Inertia::render('Stock/Index', [
            'branches_enabled' => BranchInventory::branchesEnabled($businessId),
            'active_branch' => BranchInventory::branchesEnabled($businessId) ? $activeBranch : null,
            'allow_negative_stock' => StockPolicy::allowsNegativeStockForBusinessId($businessId),
            'can_adjust_stock' => $request->user()?->hasPermission(Permissions::INVENTORY_ADJUST) ?? false,
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);
        $term = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 20);
        $limit = max(1, min($limit, 30));

        if (mb_strlen($term) < 2) {
            return response()->json(['products' => []]);
        }

        $like = "%{$term}%";
        $normalized = $this->normalizeSearchTerm($term);
        $query = Product::query()
            ->where('products.business_id', $businessId)
            ->where('products.is_active', true)
            ->with([
                'category:id,business_id,name',
                'brand:id,business_id,name',
                'productLocation:id,business_id,name',
            ])
            ->where(function ($query) use ($like, $normalized) {
                $query->whereRaw($this->normalizedSql('products.code').' = ?', [$normalized])
                    ->orWhereRaw($this->normalizedSql('products.barcode').' = ?', [$normalized])
                    ->orWhere('products.name', 'ilike', $like)
                    ->orWhere('products.code', 'ilike', $like)
                    ->orWhere('products.barcode', 'ilike', $like);
            })
            ->orderByRaw(
                'CASE WHEN '.$this->normalizedSql('products.code').' = ? OR '.$this->normalizedSql('products.barcode').' = ? THEN 0 ELSE 1 END',
                [$normalized, $normalized],
            )
            ->orderBy('products.name');

        BranchInventory::restrictProductsToBranch($query, $businessId, $branch->id);

        $products = $query
            ->limit($limit)
            ->get(['id', 'business_id', 'category_id', 'brand_id', 'location_id', 'name', 'code', 'barcode', 'sale_price', 'stock', 'min_stock', 'location', 'image_url']);

        return response()->json([
            'products' => $this->stockProductPayload($products, $businessId, $branch->id),
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'type' => ['required', 'in:increase,decrease'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'note.required' => 'La nota es obligatoria.',
            'note.min' => 'La nota debe tener al menos 5 caracteres.',
            'quantity.gt' => 'La cantidad debe ser mayor a 0.',
        ]);

        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);

        $result = DB::transaction(function () use ($request, $data, $businessId, $branch) {
            $product = Product::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            BranchInventory::ensureProductInBranch($product, $branch->id);

            $quantity = (float) $data['quantity'];
            $breakdown = StockAvailability::getBreakdownForProducts($businessId, $branch->id, [$product->id])->get($product->id, [
                'physical_stock' => 0.0,
                'reserved_total' => 0.0,
                'available_stock' => 0.0,
            ]);
            $previousAvailable = (float) $breakdown['available_stock'];

            if ($data['type'] === 'decrease'
                && ! StockPolicy::allowsNegativeStockForBusinessId($businessId)
                && $previousAvailable < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'No puedes reducir esa cantidad porque dejaría stock disponible negativo.',
                ]);
            }

            [$previousStock, $newStock] = $data['type'] === 'decrease'
                ? BranchInventory::decrease($product, $branch->id, $quantity)
                : BranchInventory::increase($product, $branch->id, $quantity);

            StockMovement::create([
                'business_id' => $businessId,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'type' => $data['type'] === 'decrease' ? 'exit' : 'entry',
                'quantity' => $data['type'] === 'decrease' ? -1 * $quantity : $quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'note' => $data['note'],
                'created_by' => $request->user()->id,
                'user_id' => $request->user()->id,
            ]);

            $updatedProduct = Product::query()
                ->with(['category:id,business_id,name', 'brand:id,business_id,name', 'productLocation:id,business_id,name'])
                ->findOrFail($product->id);

            return [
                'previous_stock' => (float) $previousStock,
                'adjustment' => $data['type'] === 'decrease' ? -1 * $quantity : $quantity,
                'new_stock' => (float) $newStock,
                'product' => $this->stockProductPayload(collect([$updatedProduct]), $businessId, $branch->id)->first(),
            ];
        });

        return response()->json([
            'message' => 'Stock ajustado correctamente.',
            ...$result,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'type' => ['required', 'in:add,remove'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $product = Product::query()
                ->where('business_id', currentBusinessId())
                ->lockForUpdate()
                ->findOrFail($data['product_id']);
            $branch = BranchInventory::activeBranch(currentBusinessId());

            $quantity = $data['type'] === 'remove'
                ? -1 * $data['quantity']
                : $data['quantity'];

            if ($quantity < 0) {
                StockPolicy::assertCanDecreaseStock(currentBusinessId(), $branch, $product, null, abs($quantity), 'stock');
            }

            [$previousStock, $newStock] = $quantity < 0
                ? BranchInventory::decrease($product, $branch->id, abs($quantity))
                : BranchInventory::increase($product, $branch->id, $quantity);

            StockMovement::create([
                'business_id' => currentBusinessId(),
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'type' => $data['type'],
                'quantity' => $quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'note' => filled($data['note'] ?? null) ? $data['note'] : stockMovementNote($data['type']),
                'created_by' => $request->user()->id,
                'user_id' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Stock actualizado.');
    }

    public function quickStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'type' => ['required', 'in:entry,exit,adjustment'],
            'quantity' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (in_array($data['type'], ['entry', 'exit'], true) && $data['quantity'] < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad debe ser mayor a 0.',
            ]);
        }

        if (in_array($data['type'], ['exit', 'adjustment'], true) && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages([
                'note' => 'La nota es obligatoria para salida y ajuste.',
            ]);
        }

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::query()
                ->where('business_id', currentBusinessId())
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($data['product_id']);
            $branch = BranchInventory::activeBranch(currentBusinessId());

            $previousStock = (float) (BranchInventory::stockMap(currentBusinessId(), [$product->id], $branch->id)[$product->id] ?? 0);
            $newStock = match ($data['type']) {
                'entry' => $previousStock + $data['quantity'],
                'exit' => $previousStock - $data['quantity'],
                'adjustment' => $data['quantity'],
            };

            $movementQuantity = $newStock - $previousStock;

            if ($movementQuantity < 0) {
                StockPolicy::assertCanDecreaseStock(currentBusinessId(), $branch, $product, null, abs($movementQuantity), 'stock');
            }

            [$previousStock, $newStock] = $data['type'] === 'adjustment'
                ? BranchInventory::adjust($product, $branch->id, $newStock)
                : ($movementQuantity < 0
                    ? BranchInventory::decrease($product, $branch->id, abs($movementQuantity))
                    : BranchInventory::increase($product, $branch->id, $movementQuantity));

            StockMovement::create([
                'business_id' => currentBusinessId(),
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'type' => $data['type'],
                'quantity' => $movementQuantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'note' => filled($data['note'] ?? null) ? $data['note'] : stockMovementNote($data['type']),
                'created_by' => $request->user()->id,
                'user_id' => $request->user()->id,
            ]);

            return $product->fresh(['category']);
        });

        return back()->with('success', 'Stock actualizado');
    }

    private function applyReservedStock($products, int $branchId): void
    {
        $products->each(function (Product $product) use ($branchId) {
            $reserved = StockAvailability::reservedStock($product, null, $branchId);
            $product->setAttribute('reserved_stock', $reserved);
            $product->setAttribute('available_stock', (float) $product->stock - $reserved);
        });
    }

    private function stockProductPayload($products, int $businessId, int $branchId)
    {
        $productIds = $products->pluck('id')->all();
        $stockBreakdown = StockAvailability::getBreakdownForProducts($businessId, $branchId, $productIds);

        return $products->map(function (Product $product) use ($stockBreakdown) {
            $breakdown = $stockBreakdown->get($product->id, [
                'physical_stock' => 0,
                'reserved_total' => 0,
                'available_stock' => 0,
            ]);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'category_name' => $product->category?->name,
                'brand_name' => $product->brand?->name,
                'location' => $product->productLocation?->name ?? $product->location,
                'sale_price' => $product->sale_price,
                'physical_stock' => (float) $breakdown['physical_stock'],
                'reserved_stock' => (float) $breakdown['reserved_total'],
                'available_stock' => (float) $breakdown['available_stock'],
            ];
        })->values();
    }

    private function normalizeSearchTerm(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($value)) ?? '');
    }

    private function normalizedSql(string $column): string
    {
        return "UPPER(regexp_replace(TRIM(COALESCE({$column}, '')), '\\s+', ' ', 'g'))";
    }
}
