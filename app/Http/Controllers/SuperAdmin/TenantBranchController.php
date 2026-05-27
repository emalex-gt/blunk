<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Support\CloudinaryUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantBranchController extends Controller
{
    public function index(Business $business): Response
    {
        $business->load(['tenantModules', 'tenantSetting']);

        return Inertia::render('SuperAdmin/Tenants/Branches', [
            'tenant' => [
                'id' => $business->id,
                'name' => $business->name,
                'country' => $business->country,
                'branches_module_enabled' => $business->tenantModules
                    ->where('module', 'branches')
                    ->where('is_enabled', true)
                    ->isNotEmpty(),
                'use_branches' => (bool) ($business->tenantSetting?->use_branches ?? false),
            ],
            'branches' => $business->branches()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'business_id', 'name', 'code', 'address', 'phone', 'logo_url', 'is_active']),
        ]);
    }

    public function store(Request $request, Business $business): RedirectResponse
    {
        $payload = $this->validated($request, $business);

        if ($request->hasFile('logo')) {
            $logo = app(CloudinaryUploader::class)->uploadImage(
                $request->file('logo'),
                "businesses/{$business->id}/branches",
                'branch_logo',
            );

            $payload['logo_url'] = $logo['secure_url'];
            $payload['logo_public_id'] = $logo['public_id'];
        }

        $business->branches()->create($payload);

        return back()->with('success', 'Sucursal creada correctamente.');
    }

    public function update(Request $request, Business $business, Branch $branch): RedirectResponse
    {
        $this->ensureBranchBelongsToBusiness($business, $branch);
        $payload = $this->validated($request, $business, $branch);
        $cloudinary = app(CloudinaryUploader::class);

        if ($request->boolean('remove_logo')) {
            $cloudinary->destroy($branch->logo_public_id);
            $payload['logo_url'] = null;
            $payload['logo_public_id'] = null;
        }

        if ($request->hasFile('logo')) {
            $oldPublicId = $branch->logo_public_id;
            $logo = $cloudinary->uploadImage(
                $request->file('logo'),
                "businesses/{$business->id}/branches",
                'branch_logo',
            );

            if (! $request->boolean('remove_logo')) {
                $cloudinary->destroy($oldPublicId);
            }

            $payload['logo_url'] = $logo['secure_url'];
            $payload['logo_public_id'] = $logo['public_id'];
        }

        $branch->update($payload);

        return back()->with('success', 'Sucursal actualizada correctamente.');
    }

    public function destroy(Business $business, Branch $branch): RedirectResponse
    {
        $this->ensureBranchBelongsToBusiness($business, $branch);

        $activeBranches = $business->branches()->where('is_active', true)->count();

        if ($branch->is_active && $activeBranches <= 1) {
            throw ValidationException::withMessages([
                'branch' => 'No se puede eliminar la última sucursal activa.',
            ]);
        }

        app(CloudinaryUploader::class)->destroy($branch->logo_public_id);
        $branch->delete();

        return back()->with('success', 'Sucursal eliminada correctamente.');
    }

    private function validated(Request $request, Business $business, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('branches', 'code')
                    ->where('business_id', $business->id)
                    ->ignore($branch),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'logo.max' => 'El logo no debe superar los 5MB.',
        ]);

        return [
            'business_id' => $business->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function ensureBranchBelongsToBusiness(Business $business, Branch $branch): void
    {
        abort_unless((int) $branch->business_id === (int) $business->id, 404);
    }
}
