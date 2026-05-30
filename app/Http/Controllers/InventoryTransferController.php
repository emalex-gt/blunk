<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryTransferController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, Permissions::INVENTORY_TRANSFERS_VIEW);
        $businessId = currentBusinessId();

        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => InventoryTransfer::query()
                ->where('business_id', $businessId)
                ->when(! BranchInventory::canSwitchBranches($request->user()), function ($query) use ($businessId) {
                    $branchId = BranchInventory::activeBranch($businessId)->id;
                    $query->where(function ($query) use ($branchId) {
                        $query->where('from_branch_id', $branchId)
                            ->orWhere('to_branch_id', $branchId);
                    });
                })
                ->with(['fromBranch:id,name', 'toBranch:id,name', 'createdBy:id,name'])
                ->latest()
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePermission($request, Permissions::INVENTORY_TRANSFERS_CREATE);
        $businessId = currentBusinessId();
        $activeBranch = BranchInventory::activeBranch($businessId);
        $products = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'barcode', 'stock']);

        BranchInventory::applyBranchStockAndPrices($products, $businessId, $activeBranch->id);
        $products->each(function (Product $product) use ($activeBranch) {
            $reserved = StockAvailability::reservedStock($product, null, $activeBranch->id);
            $product->setAttribute('reserved_stock', $reserved);
            $product->setAttribute('available_stock', max(0, (float) $product->stock - $reserved));
        });

        return Inertia::render('Inventory/Transfers/Create', [
            'branches' => BranchInventory::branchOptions($businessId),
            'activeBranch' => $activeBranch,
            'products' => $products,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, Permissions::INVENTORY_TRANSFERS_CREATE);

        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:from_branch_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $transfer = DB::transaction(function () use ($request, $data) {
            $businessId = currentBusinessId();
            $from = $this->branchForBusiness((int) $data['from_branch_id'], $businessId);
            $to = $this->branchForBusiness((int) $data['to_branch_id'], $businessId);
            $activeBranch = BranchInventory::activeBranch($businessId);

            if (! BranchInventory::canSwitchBranches($request->user()) && (int) $from->id !== (int) $activeBranch->id) {
                throw ValidationException::withMessages([
                    'from_branch_id' => 'No tienes permiso para trasladar desde otra sucursal.',
                ]);
            }

            if ($from->id === $to->id) {
                throw ValidationException::withMessages([
                    'to_branch_id' => 'La sucursal destino debe ser diferente.',
                ]);
            }

            $transfer = InventoryTransfer::create([
                'business_id' => $businessId,
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $line) {
                $product = Product::query()
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->find((int) $line['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno o mas productos no pertenecen a esta empresa.',
                    ]);
                }

                $quantity = (int) $line['quantity'];
                $available = StockAvailability::availableStock($product, null, $from->id);

                if ($available < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => 'No hay suficiente disponible para trasladar.',
                    ]);
                }

                [$previousFrom, $newFrom] = BranchInventory::decrease($product, $from->id, $quantity);
                [$previousTo, $newTo] = BranchInventory::increase($product, $to->id, $quantity);

                $transfer->lines()->create([
                    'business_id' => $businessId,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]);

                StockMovement::create([
                    'business_id' => $businessId,
                    'branch_id' => $from->id,
                    'product_id' => $product->id,
                    'type' => 'transfer_out',
                    'quantity' => -1 * $quantity,
                    'previous_stock' => $previousFrom,
                    'new_stock' => $newFrom,
                    'note' => "Traslado #{$transfer->id} hacia {$to->name}",
                    'created_by' => $request->user()->id,
                    'user_id' => $request->user()->id,
                ]);

                StockMovement::create([
                    'business_id' => $businessId,
                    'branch_id' => $to->id,
                    'product_id' => $product->id,
                    'type' => 'transfer_in',
                    'quantity' => $quantity,
                    'previous_stock' => $previousTo,
                    'new_stock' => $newTo,
                    'note' => "Traslado #{$transfer->id} desde {$from->name}",
                    'created_by' => $request->user()->id,
                    'user_id' => $request->user()->id,
                ]);
            }

            return $transfer;
        });

        return redirect()->route('inventory.transfers.show', $transfer)->with('success', 'Traslado registrado correctamente.');
    }

    public function show(Request $request, InventoryTransfer $transfer): Response
    {
        $this->authorizePermission($request, Permissions::INVENTORY_TRANSFERS_VIEW);
        abort_unless((int) $transfer->business_id === (int) currentBusinessId(), 403);
        abort_unless(
            BranchInventory::canSwitchBranches($request->user())
            || in_array(BranchInventory::activeBranch(currentBusinessId())->id, [(int) $transfer->from_branch_id, (int) $transfer->to_branch_id], true),
            403,
        );

        return Inertia::render('Inventory/Transfers/Show', [
            'transfer' => $transfer->load(['fromBranch:id,name', 'toBranch:id,name', 'createdBy:id,name', 'lines.product:id,name,code']),
        ]);
    }

    private function branchForBusiness(int $branchId, int $businessId): Branch
    {
        return Branch::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->findOrFail($branchId);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless(Permissions::userHas($request->user(), $permission), 403);
    }
}
