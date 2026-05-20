<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeManage($request, Permissions::BRANCHES_VIEW);
        $businessId = currentBusinessId();
        BranchInventory::defaultBranch($businessId);

        return Inertia::render('Branches/Index', [
            'branches' => Branch::query()
                ->where('business_id', $businessId)
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeManage($request, Permissions::BRANCHES_MANAGE);

        return Inertia::render('Branches/Form', [
            'branch' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request, Permissions::BRANCHES_MANAGE);
        $businessId = currentBusinessId();

        Branch::create($this->validated($request, $businessId));

        return redirect()->route('branches.index')->with('success', 'Sucursal creada correctamente.');
    }

    public function edit(Request $request, Branch $branch): Response
    {
        $this->authorizeBranch($request, $branch, Permissions::BRANCHES_MANAGE);

        return Inertia::render('Branches/Form', [
            'branch' => $branch,
        ]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeBranch($request, $branch, Permissions::BRANCHES_MANAGE);
        $branch->update($this->validated($request, (int) $branch->business_id, $branch));

        return redirect()->route('branches.index')->with('success', 'Sucursal actualizada correctamente.');
    }

    public function activate(Request $request): RedirectResponse
    {
        abort_unless(BranchInventory::branchesEnabled(), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        BranchInventory::setActiveBranch(currentBusinessId(), (int) $data['branch_id']);

        return back()->with('success', 'Sucursal activa actualizada.');
    }

    private function validated(Request $request, int $businessId, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('branches', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($branch),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        return [
            'business_id' => $businessId,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function authorizeBranch(Request $request, Branch $branch, string $permission): void
    {
        abort_unless((int) $branch->business_id === (int) currentBusinessId(), 403);
        $this->authorizeManage($request, $permission);
    }

    private function authorizeManage(Request $request, string $permission): void
    {
        abort_unless(Permissions::userHas($request->user(), $permission), 403);
    }
}
