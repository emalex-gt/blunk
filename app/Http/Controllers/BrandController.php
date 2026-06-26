<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $search = $request->string('search')->toString();

        return Inertia::render('Brands/Index', [
            'brands' => Brand::query()
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

        Brand::query()->create($data);

        return back()->with('success', 'Marca creada correctamente.');
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorizeBrand($brand);
        $brand->update($this->validated($request, $brand));

        return back()->with('success', 'Marca actualizada correctamente.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->authorizeBrand($brand);

        $brand->update(['is_active' => false]);

        return back()->with('success', 'Marca desactivada correctamente.');
    }

    private function validated(Request $request, ?Brand $brand = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre de la marca es obligatorio.',
        ]);

        $data['name'] = preg_replace('/\s+/', ' ', trim($data['name']));

        $exists = Brand::query()
            ->where('business_id', currentBusinessId())
            ->when($brand, fn ($query) => $query->whereKeyNot($brand->id))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe una marca con este nombre.',
            ]);
        }

        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    private function authorizeBrand(Brand $brand): void
    {
        abort_unless((int) $brand->business_id === (int) currentBusinessId(), 403);
    }
}
