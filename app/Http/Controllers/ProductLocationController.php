<?php

namespace App\Http\Controllers;

use App\Models\ProductLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductLocationController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $search = $request->string('search')->toString();

        return Inertia::render('ProductLocations/Index', [
            'locations' => ProductLocation::query()
                ->where('business_id', $businessId)
                ->withCount('products')
                ->when($search, fn ($query) => $query->where('name', 'ilike', "%{$search}%"))
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['business_id'] = currentBusinessId();

        ProductLocation::query()->create($data);

        return back()->with('success', 'Ubicación creada correctamente.');
    }

    public function update(Request $request, ProductLocation $productLocation): RedirectResponse
    {
        $this->authorizeLocation($productLocation);
        $productLocation->update($this->validated($request, $productLocation));

        return back()->with('success', 'Ubicación actualizada correctamente.');
    }

    public function destroy(ProductLocation $productLocation): RedirectResponse
    {
        $this->authorizeLocation($productLocation);

        $productLocation->update(['is_active' => false]);

        return back()->with('success', 'Ubicación desactivada correctamente.');
    }

    private function validated(Request $request, ?ProductLocation $productLocation = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre de la ubicación es obligatorio.',
        ]);

        $data['name'] = $this->normalizeName($data['name']);

        $exists = ProductLocation::query()
            ->where('business_id', currentBusinessId())
            ->when($productLocation, fn ($query) => $query->whereKeyNot($productLocation->id))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe una ubicación con este nombre.',
            ]);
        }

        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim($name));
    }

    private function authorizeLocation(ProductLocation $productLocation): void
    {
        abort_unless((int) $productLocation->business_id === (int) currentBusinessId(), 403);
    }
}
