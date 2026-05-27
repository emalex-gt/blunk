<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function roles(): Response
    {
        Permissions::syncDefaults();

        return Inertia::render('SuperAdmin/Security/Roles', [
            'roles' => Role::query()
                ->with('permissions:id,key,name,group')
                ->whereNull('business_id')
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::query()
                ->orderBy('group')
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'group']),
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/', Rule::unique('roles', 'key')->whereNull('business_id')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $role = Role::query()->create([
            'business_id' => null,
            'name' => $data['name'],
            'key' => $data['key'],
            'is_system' => false,
        ]);
        $this->syncRolePermissions($role, $data['permissions'] ?? []);

        return back()->with('success', 'Rol creado.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        abort_unless($role->business_id === null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $role->update(['name' => $data['name']]);
        $this->syncRolePermissions($role, $data['permissions'] ?? []);

        return back()->with('success', 'Rol actualizado.');
    }

    public function destroyRole(Role $role): RedirectResponse
    {
        abort_unless($role->business_id === null, 404);
        abort_if($role->is_system, 422, 'No se puede eliminar un rol del sistema.');

        $role->delete();

        return back()->with('success', 'Rol eliminado.');
    }

    public function permissions(): Response
    {
        Permissions::syncDefaults();

        return Inertia::render('SuperAdmin/Security/Permissions', [
            'permissions' => Permission::query()
                ->orderBy('group')
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'group', 'description']),
        ]);
    }

    public function assignments(Request $request): Response
    {
        Permissions::syncDefaults();
        $businessId = $request->integer('business_id') ?: Business::query()->orderBy('name')->value('id');

        return Inertia::render('SuperAdmin/Security/Assignments', [
            'businesses' => Business::query()->orderBy('name')->get(['id', 'name']),
            'selectedBusinessId' => $businessId,
            'users' => $businessId
                ? User::query()
                    ->where('business_id', $businessId)
                    ->with(['roles:id,key,name', 'directPermissions:id,key,name'])
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'role'])
                : [],
            'roles' => Role::query()->whereNull('business_id')->orderBy('name')->get(['id', 'key', 'name']),
            'permissions' => Permission::query()->orderBy('group')->orderBy('name')->get(['id', 'key', 'name', 'group']),
        ]);
    }

    public function updateAssignment(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'key')->whereNull('business_id')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $roleIds = Role::query()
            ->whereNull('business_id')
            ->whereIn('key', $data['roles'] ?? [])
            ->pluck('id')
            ->all();
        $user->roles()->sync($roleIds);

        if (($data['roles'] ?? []) !== []) {
            $user->forceFill(['role' => $data['roles'][0]])->save();
        }

        Permissions::assignDirectPermissions($user, $data['permissions'] ?? []);

        return back()->with('success', 'Asignación actualizada.');
    }

    private function syncRolePermissions(Role $role, array $permissionKeys): void
    {
        $ids = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($ids);
    }
}
