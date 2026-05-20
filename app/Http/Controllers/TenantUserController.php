<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantUserController extends Controller
{
    private const ASSIGNABLE_ROLES = ['admin', 'cashier', 'stock_manager'];

    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $settings = TenantSetting::query()->firstOrCreate(
            ['business_id' => $businessId],
            ['use_product_images' => true, 'max_users' => 1],
        );

        $users = User::query()
            ->where('business_id', $businessId)
            ->where('is_super_admin', false)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active']);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'limits' => [
                'active_users' => $users->where('is_active', true)->count(),
                'max_users' => $settings->max_users,
            ],
            'roles' => self::ASSIGNABLE_ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = currentBusinessId();
        $this->ensureUserLimitAllowsCreate($businessId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        User::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],
            'is_super_admin' => false,
            'is_active' => true,
        ]);

        return back()->with('success', 'Usuario creado.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTenantUser($request, $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in($user->role === 'owner' ? ['owner'] : self::ASSIGNABLE_ROLES)],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->id === $request->user()->id && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => 'No puedes desactivar tu propio usuario.',
            ]);
        }

        if ($user->role === 'owner' && $data['role'] !== 'owner') {
            $this->ensureAnotherActiveOwnerExists(currentBusinessId(), $user->id);
        }

        if ($user->role === 'owner' && ! $data['is_active']) {
            $this->ensureAnotherActiveOwnerExists(currentBusinessId(), $user->id);
        }

        if (! $user->is_active && $data['is_active']) {
            $this->ensureUserLimitAllowsCreate(currentBusinessId());
        }

        $user->update([
            'name' => $data['name'],
            'role' => $data['role'],
            'is_active' => $data['is_active'],
        ]);

        return back()->with('success', 'Usuario actualizado.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTenantUser($request, $user);

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
}
