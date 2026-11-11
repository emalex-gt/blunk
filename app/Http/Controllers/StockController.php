<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\BranchInventory;
use App\Support\IdempotencyService;
use App\Support\Inventory\ProductQuantityPolicy;
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
            ->get(['id', 'name', 'code', 'barcode', 'stock', 'min_stock', 'allow_decimal_quantity', 'location', 'image_url']);
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
            ->get(['id', 'business_id', 'category_id', 'brand_id', 'location_id', 'name', 'code', 'barcode', 'sale_price', 'stock', 'min_stock', 'allow_decimal_quantity', 'location', 'image_url']);

        return response()->json([
            'products' => $this->stockProductPayload($products, $businessId, $branch->id),
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'product_id' => ['required', 'integer'],
            'type' => ['required', 'in:increase,decrease'],
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'note.required' => 'La nota es obligatoria.',
            'note.min' => 'La nota debe tener al menos 5 caracteres.',
            'quantity.decimal' => 'La cantidad debe tener como máximo cuatro decimales.',
            'quantity.gt' => 'La cantidad debe ser mayor a 0.',
        ]);

        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);

        $idempotencyResult = app(IdempotencyService::class)->run(
            $businessId,
            $branch->id,
            $request->user()->id,
            'stock_adjustment_ajax',
            $data['idempotency_key'],
            $this->stockIdempotencyPayload($data, $businessId, $branch->id, $request->user()->id, 'stock_adjustment_ajax'),
            function () use ($request, $data, $businessId, $branch) {
                $result = DB::transaction(function () use ($request, $data, $businessId, $branch) {
            $product = Product::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            BranchInventory::ensureProductInBranch($product, $branch->id);

            $quantity = ProductQuantityPolicy::positive($product, $data['quantity']);
            $breakdown = StockAvailability::getBreakdownForProducts($businessId, $branch->id, [$product->id])->get($product->id, [
                'physical_stock' => '0',
                'reserved_total' => '0',
                'available_stock' => '0',
            ]);
            $previousAvailable = $breakdown['available_stock'];

            if ($data['type'] === 'decrease'
                && ! StockPolicy::allowsNegativeStockForBusinessId($businessId)
                && ! ProductQuantityPolicy::isAvailable($previousAvailable, $quantity)) {
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
                'quantity' => $data['type'] === 'decrease' ? '-'.$quantity : $quantity,
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
                'previous_stock' => $previousStock,
                'adjustment' => $data['type'] === 'decrease' ? '-'.$quantity : $quantity,
                'new_stock' => $newStock,
                'product' => $this->stockProductPayload(collect([$updatedProduct]), $businessId, $branch->id)->first(),
            ];
                });

                return [
                    'result_id' => (int) $data['product_id'],
                    'response_payload' => $result,
                ];
            },
            'stock_adjustment',
        );
        $result = $idempotencyResult->responsePayload;

        return response()->json([
            'message' => 'Stock ajustado correctamente.',
            ...$result,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'product_id' => ['required', 'exists:products,id'],
            'type' => ['required', 'in:add,remove'],
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);

        app(IdempotencyService::class)->run(
            $businessId,
            $branch->id,
            $request->user()->id,
            'stock_store',
            $data['idempotency_key'],
            $this->stockIdempotencyPayload($data, $businessId, $branch->id, $request->user()->id, 'stock_store'),
            function () use ($request, $data, $businessId, $branch) {
                DB::transaction(function () use ($request, $data, $businessId, $branch) {
            $product = Product::query()
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            $enteredQuantity = ProductQuantityPolicy::positive($product, $data['quantity']);
            $quantity = $data['type'] === 'remove' ? '-'.$enteredQuantity : $enteredQuantity;

            if (ProductQuantityPolicy::compare($quantity, '0') < 0) {
                StockPolicy::assertCanDecreaseStock($businessId, $branch, $product, null, $enteredQuantity, 'stock');
            }

            [$previousStock, $newStock] = ProductQuantityPolicy::compare($quantity, '0') < 0
                ? BranchInventory::decrease($product, $branch->id, $enteredQuantity)
                : BranchInventory::increase($product, $branch->id, $quantity);

            StockMovement::create([
                'business_id' => $businessId,
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

                return ['result_id' => (int) $data['product_id']];
            },
            'stock_adjustment',
        );

        return back()->with('success', 'Stock actualizado.');
    }

    public function quickStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'product_id' => ['required', 'exists:products,id'],
            'type' => ['required', 'in:entry,exit,adjustment'],
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'gte:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (in_array($data['type'], ['exit', 'adjustment'], true) && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages([
                'note' => 'La nota es obligatoria para salida y ajuste.',
            ]);
        }

        $businessId = currentBusinessId();
        $branch = BranchInventory::activeBranch($businessId);

        app(IdempotencyService::class)->run(
            $businessId,
            $branch->id,
            $request->user()->id,
            'stock_quick_store',
            $data['idempotency_key'],
            $this->stockIdempotencyPayload($data, $businessId, $branch->id, $request->user()->id, 'stock_quick_store'),
            function () use ($request, $data, $businessId, $branch) {
                $product = DB::transaction(function () use ($request, $data, $businessId, $branch) {
            $product = Product::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            $enteredQuantity = ProductQuantityPolicy::absolute($product, $data['quantity']);
            if (in_array($data['type'], ['entry', 'exit'], true) && ProductQuantityPolicy::compare($enteredQuantity, '0') === 0) {
                throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor a 0.']);
            }

            $previousStock = ProductQuantityPolicy::signed(BranchInventory::stockMap($businessId, [$product->id], $branch->id)[$product->id] ?? 0);
            $newStock = match ($data['type']) {
                'entry' => ProductQuantityPolicy::add($previousStock, $enteredQuantity),
                'exit' => ProductQuantityPolicy::subtract($previousStock, $enteredQuantity),
                'adjustment' => $enteredQuantity,
            };

            $movementQuantity = ProductQuantityPolicy::subtract($newStock, $previousStock);

            if (ProductQuantityPolicy::compare($movementQuantity, '0') < 0) {
                StockPolicy::assertCanDecreaseStock($businessId, $branch, $product, null, ltrim($movementQuantity, '-'), 'stock');
            }

            [$previousStock, $newStock] = $data['type'] === 'adjustment'
                ? BranchInventory::adjust($product, $branch->id, $newStock)
                : (ProductQuantityPolicy::compare($movementQuantity, '0') < 0
                    ? BranchInventory::decrease($product, $branch->id, ltrim($movementQuantity, '-'))
                    : BranchInventory::increase($product, $branch->id, $movementQuantity));

            StockMovement::create([
                'business_id' => $businessId,
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

                return ['result_id' => $product->id];
            },
            'stock_adjustment',
        );

        return back()->with('success', 'Stock actualizado');
    }

    private function stockIdempotencyPayload(array $data, int $businessId, int $branchId, int $userId, string $operationType): array
    {
        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'operation_type' => $operationType,
            'product_id' => (int) ($data['product_id'] ?? 0),
            'type' => $data['type'] ?? null,
            'quantity' => ProductQuantityPolicy::canonicalForIdempotency($data['quantity'] ?? 0),
            'note' => $data['note'] ?? null,
        ];
    }

    private function applyReservedStock($products, int $branchId): void
    {
        $products->each(function (Product $product) use ($branchId) {
            $reserved = StockAvailability::reservedStock($product, null, $branchId);
            $product->setAttribute('reserved_stock', $reserved);
            $product->setAttribute('available_stock', ProductQuantityPolicy::subtract($product->stock, $reserved));
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
                'allow_decimal_quantity' => (bool) $product->allow_decimal_quantity,
                'physical_stock' => $breakdown['physical_stock'],
                'reserved_stock' => $breakdown['reserved_total'],
                'available_stock' => $breakdown['available_stock'],
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
