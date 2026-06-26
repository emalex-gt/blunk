<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $search = $request->string('search')->toString();

        return Inertia::render('Categories/Index', [
            'categories' => Category::query()
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

        Category::query()->create($data);

        return back()->with('success', 'Categoría creada correctamente.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeCategory($category);
        $category->update($this->validated($request, $category));

        return back()->with('success', 'Categoría actualizada correctamente.');
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [
            'name.required' => 'El nombre de la categoría es obligatorio.',
        ]);

        $data['name'] = preg_replace('/\s+/', ' ', trim($data['name']));

        $exists = Category::query()
            ->where('business_id', currentBusinessId())
            ->when($category, fn ($query) => $query->whereKeyNot($category->id))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe una categoría con este nombre.',
            ]);
        }

        return $data;
    }

    private function authorizeCategory(Category $category): void
    {
        abort_unless((int) $category->business_id === (int) currentBusinessId(), 403);
    }
}
