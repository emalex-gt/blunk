<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\BranchInventory;
use App\Support\Inventory\StockPolicy;
use App\Support\StockAvailability;
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
        $products = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'stock', 'location']);
        BranchInventory::applyBranchStockAndPrices($products, $businessId, $activeBranch->id);
        $this->applyReservedStock($products, $activeBranch->id);

        return Inertia::render('Stock/Index', [
            'products' => $products,
            'branches_enabled' => BranchInventory::branchesEnabled($businessId),
            'active_branch' => BranchInventory::branchesEnabled($businessId) ? $activeBranch : null,
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
}
