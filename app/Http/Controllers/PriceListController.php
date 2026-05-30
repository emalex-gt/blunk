<?php

namespace App\Http\Controllers;

use App\Models\PriceType;
use App\Models\Product;
use App\Support\BranchInventory;
use App\Support\PriceLists;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeManage($request);
        $businessId = currentBusinessId();
        PriceLists::ensureDefault($businessId);

        return Inertia::render('PriceLists/Index', [
            'priceTypes' => PriceType::query()
                ->where('business_id', $businessId)
                ->withCount(['productPrices as products_with_price_count' => fn ($query) => $query->where('is_active', true)])
                ->orderByDesc('is_default')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'activeCount' => PriceType::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('PriceLists/Form', [
            'priceType' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $businessId = currentBusinessId();
        $data = $this->validated($request);

        DB::transaction(function () use ($businessId, $data) {
            $activeCount = PriceType::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->count();

            $isActive = (bool) ($data['is_active'] ?? true);
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($activeCount === 0 && $isActive) {
                $isDefault = true;
            }

            if ($isDefault) {
                PriceType::query()
                    ->where('business_id', $businessId)
                    ->update(['is_default' => false]);
                $isActive = true;
            }

            PriceType::query()->create([
                'business_id' => $businessId,
                'name' => trim($data['name']),
                'is_active' => $isActive,
                'is_default' => $isDefault,
            ]);

            PriceLists::ensureDefault($businessId);
        });

        return redirect()->route('price-lists.index')->with('success', 'Lista de precios creada.');
    }

    public function edit(Request $request, PriceType $priceType): Response
    {
        $this->authorizePriceType($request, $priceType);

        return Inertia::render('PriceLists/Form', [
            'priceType' => $priceType,
        ]);
    }

    public function update(Request $request, PriceType $priceType): RedirectResponse
    {
        $this->authorizePriceType($request, $priceType);
        $businessId = currentBusinessId();
        $data = $this->validated($request, $priceType);

        DB::transaction(function () use ($businessId, $priceType, $data) {
            $isActive = (bool) ($data['is_active'] ?? false);
            $isDefault = (bool) ($data['is_default'] ?? false);

            if (! $isActive && $priceType->is_active && $this->activeCount($businessId) <= 1) {
                throw ValidationException::withMessages([
                    'is_active' => 'Debe existir al menos una lista de precios activa.',
                ]);
            }

            if ($isDefault) {
                PriceType::query()
                    ->where('business_id', $businessId)
                    ->whereKeyNot($priceType->id)
                    ->update(['is_default' => false]);
                $isActive = true;
            }

            $priceType->update([
                'name' => trim($data['name']),
                'is_active' => $isActive,
                'is_default' => $isDefault,
            ]);

            PriceLists::ensureDefault($businessId);
        });

        return redirect()->route('price-lists.index')->with('success', 'Lista de precios actualizada.');
    }

    public function destroy(Request $request, PriceType $priceType): RedirectResponse
    {
        $this->authorizePriceType($request, $priceType);
        $businessId = currentBusinessId();

        if ($priceType->is_active && $this->activeCount($businessId) <= 1) {
            throw ValidationException::withMessages([
                'price_list' => 'No se puede eliminar o desactivar la última lista activa.',
            ]);
        }

        $priceType->delete();
        PriceLists::ensureDefault($businessId);

        return redirect()->route('price-lists.index')->with('success', 'Lista de precios eliminada.');
    }

    public function setDefault(Request $request, PriceType $priceType): RedirectResponse
    {
        $this->authorizePriceType($request, $priceType);
        PriceLists::setDefault(currentBusinessId(), $priceType->id);

        return back()->with('success', 'Lista predeterminada actualizada.');
    }

    public function prices(Request $request, PriceType $priceType): Response
    {
        $this->authorizePriceType($request, $priceType);
        $businessId = currentBusinessId();
        $activeBranch = BranchInventory::activeBranch($businessId);
        $pricingScope = BranchInventory::pricingScope($businessId);
        $search = $request->string('search')->toString();

        $products = Product::query()
            ->where('business_id', $businessId)
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%");
                });
            })
            ->with(['prices' => fn ($query) => $query
                ->where('business_id', $businessId)
                ->where('price_type_id', $priceType->id)])
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();
        PriceLists::applyBranchPricesToProducts($products->getCollection(), $businessId, $activeBranch->id, $priceType->id);

        return Inertia::render('PriceLists/Prices', [
            'priceType' => $priceType,
            'products' => $products,
            'filters' => ['search' => $search],
            'pricingScope' => $pricingScope,
            'activeBranch' => $pricingScope === 'branch' ? [
                'id' => $activeBranch->id,
                'name' => $activeBranch->name,
            ] : null,
        ]);
    }

    public function updatePrices(Request $request, PriceType $priceType): RedirectResponse
    {
        $this->authorizePriceType($request, $priceType);
        $data = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('business_id', currentBusinessId()),
            ],
            'prices.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        $branchId = BranchInventory::pricingScope(currentBusinessId()) === 'branch'
            ? BranchInventory::activeBranch(currentBusinessId())->id
            : null;

        PriceLists::updateProductPrices($priceType->id, $data['prices'], $branchId);

        return back()->with('success', 'Precios actualizados.');
    }

    private function validated(Request $request, ?PriceType $priceType = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('price_types', 'name')
                    ->where('business_id', currentBusinessId())
                    ->ignore($priceType?->id),
            ],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizePriceType(Request $request, PriceType $priceType): void
    {
        abort_unless((int) $priceType->business_id === (int) currentBusinessId(), 403);
        $this->authorizeManage($request);
    }

    private function authorizeManage(Request $request): void
    {
        $user = $request->user();

        abort_unless(module_enabled('inventory') || module_enabled('pos'), 403);
        abort_unless(Permissions::canManagePriceLists($user), 403);
    }

    private function activeCount(int $businessId): int
    {
        return PriceType::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->count();
    }
}
