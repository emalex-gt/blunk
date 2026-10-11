<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use App\Models\User;
use App\Services\BranchAccessService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantUserController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        Permissions::syncDefaults();
        $settings = TenantSetting::query()->firstOrCreate(
            ['business_id' => $businessId],
            ['use_product_images' => true, 'max_users' => 1],
        );

        $users = User::query()
            ->where('business_id', $businessId)
            ->where('is_super_admin', false)
            ->with(['currentBranch:id,name,code', 'branchAccesses:business_id,user_id,branch_id'])
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'current_branch_id', 'all_branches_access']);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'limits' => [
                'active_users' => $users->where('is_active', true)->count(),
                'max_users' => $settings->max_users,
            ],
            'roles' => Permissions::assignableTenantRoles(),
            'role_options' => $this->roleOptions(),
            'branches_enabled' => BranchInventory::branchesEnabled($businessId),
            'branches' => BranchInventory::branchesEnabled($businessId)
                ? BranchInventory::branchOptions($businessId)
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Permissions::userHas($request->user(), Permissions::USERS_CREATE), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::USERS_ASSIGN_ROLES), 403);

        $businessId = currentBusinessId();
        $branchesEnabled = BranchInventory::branchesEnabled($businessId);
        $this->ensureUserLimitAllowsCreate($businessId);

        $data = $request->validate($this->rules($request, $businessId, $branchesEnabled));

        $user = User::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],
            'is_super_admin' => false,
            'is_active' => true,
            'current_branch_id' => $branchesEnabled ? ($data['current_branch_id'] ?? BranchInventory::defaultBranch($businessId)->id) : null,
        ]);
        Permissions::assignRole($user, $data['role']);

        $this->persistBranchAccess($user, $data, $branchesEnabled);

        return back()->with('success', 'Usuario creado.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless(Permissions::userHas($request->user(), Permissions::USERS_UPDATE), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::USERS_ASSIGN_ROLES), 403);

        $this->authorizeTenantUser($request, $user);
        $businessId = currentBusinessId();
        $branchesEnabled = BranchInventory::branchesEnabled($businessId);

        $data = $request->validate($this->rules($request, $businessId, $branchesEnabled, $user));

        if ($user->id === $request->user()->id && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => 'No puedes desactivar tu propio usuario.',
            ]);
        }

        if ($user->role === 'owner' && $data['role'] !== 'owner') {
            $this->ensureAnotherActiveOwnerExists($businessId, $user->id);
        }

        if ($user->role === 'owner' && ! $data['is_active']) {
            $this->ensureAnotherActiveOwnerExists($businessId, $user->id);
        }

        if (! $user->is_active && $data['is_active']) {
            $this->ensureUserLimitAllowsCreate($businessId);
        }

        $user->update([
            'name' => $data['name'],
            'role' => $data['role'],
            'is_active' => $data['is_active'],
            'current_branch_id' => $branchesEnabled ? $user->current_branch_id : null,
        ]);
        Permissions::assignRole($user, $data['role']);
        $this->persistBranchAccess($user, $data, $branchesEnabled);

        return back()->with('success', 'Usuario actualizado.');
    }

    private function rules(Request $request, int $businessId, bool $branchesEnabled, ?User $user = null): array
    {
        $creating = $user === null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $creating ? ['required', 'email', 'max:255', 'unique:users,email'] : ['nullable', 'email', 'max:255'],
            'role' => ['required', Rule::in($user?->role === 'owner' ? ['owner'] : Permissions::assignableTenantRoles())],
            'password' => $creating ? ['required', 'string', 'min:8', 'confirmed'] : ['nullable', 'string', 'min:8'],
            'password_confirmation' => $creating ? ['required'] : ['nullable'],
            'is_active' => $creating ? ['nullable', 'boolean'] : ['required', 'boolean'],
            'current_branch_id' => [
                'nullable',
                Rule::excludeIf(! $branchesEnabled),
                'integer',
                Rule::exists('branches', 'id')
                    ->where('business_id', $businessId)
                    ->where('is_active', true),
            ],
            'branch_access_mode' => [
                Rule::excludeIf(! $branchesEnabled),
                Rule::requiredIf($branchesEnabled),
                Rule::in(['all', 'selected']),
            ],
            'branch_ids' => [
                Rule::excludeIf(! $branchesEnabled),
                Rule::requiredIf($branchesEnabled && $request->input('branch_access_mode') === 'selected'),
                'array',
                'min:1',
            ],
            'branch_ids.*' => [
                Rule::excludeIf(! $branchesEnabled),
                'integer',
                'distinct',
                Rule::exists('branches', 'id')
                    ->where('business_id', $businessId)
                    ->where('is_active', true),
            ],
        ];
    }

    private function persistBranchAccess(User $user, array $data, bool $branchesEnabled): void
    {
        if (! $branchesEnabled) {
            return;
        }

        $selected = ($data['branch_access_mode'] ?? null) === 'selected';
        $branchIds = collect($data['branch_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all();
        $preferredBranchId = isset($data['current_branch_id']) ? (int) $data['current_branch_id'] : null;

        if ($selected && $preferredBranchId && ! in_array($preferredBranchId, $branchIds, true)) {
            throw ValidationException::withMessages([
                'current_branch_id' => 'La sucursal asignada debe estar dentro de las sucursales seleccionadas.',
            ]);
        }

        app(BranchAccessService::class)->replaceAccess($user, ! $selected, $branchIds, $preferredBranchId);
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTenantUser($request, $user);
        $requiredPermission = $user->is_active ? Permissions::USERS_DELETE : Permissions::USERS_UPDATE;

        abort_unless(Permissions::userHas($request->user(), $requiredPermission), 403);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'No puedes desactivar tu propio usuario.',
            ]);
        }

        if ($user->is_active && $user->role === 'owner') {
            $this->ensureAnotherActiveOwnerExists(currentBusinessId(), $user->id);
        }

        if (! $user->is_active) {
            $this->ensureUserLimitAllowsCreate(currentBusinessId());
        }

        $newStatus = ! $user->is_active;
        $user->update(['is_active' => $newStatus]);

        return back()->with('success', $newStatus ? 'Usuario activado.' : 'Usuario desactivado.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        abort_unless(Permissions::userHas($request->user(), Permissions::USERS_UPDATE), 403);

        $this->authorizeTenantUser($request, $user);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => $data['password']]);

        return back()->with('success', 'Contraseña actualizada.');
    }

    private function authorizeTenantUser(Request $request, User $user): void
    {
        abort_unless($user->business_id === currentBusinessId(), 404);
        abort_if($user->is_super_admin, 403);
    }

    private function ensureUserLimitAllowsCreate(int $businessId): void
    {
        $settings = TenantSetting::query()->firstOrCreate(
            ['business_id' => $businessId],
            ['use_product_images' => true, 'max_users' => 1],
        );

        $activeUsers = User::query()
            ->where('business_id', $businessId)
            ->where('is_super_admin', false)
            ->where('is_active', true)
            ->count();

        if ($activeUsers >= $settings->max_users) {
            throw ValidationException::withMessages([
                'users' => 'Has alcanzado el límite de usuarios permitidos para tu plan.',
            ]);
        }
    }

    private function ensureAnotherActiveOwnerExists(int $businessId, int $excludedUserId): void
    {
        $hasAnotherOwner = User::query()
            ->where('business_id', $businessId)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->whereKeyNot($excludedUserId)
            ->exists();

        if (! $hasAnotherOwner) {
            throw ValidationException::withMessages([
                'role' => 'Debe quedar al menos un propietario activo.',
            ]);
        }
    }

    private function roleOptions(): array
    {
        $labels = Permissions::roleLabels();

        return collect(Permissions::assignableTenantRoles())
            ->map(fn (string $key) => [
                'key' => $key,
                'name' => $labels[$key] ?? $key,
            ])
            ->values()
            ->all();
    }

}
