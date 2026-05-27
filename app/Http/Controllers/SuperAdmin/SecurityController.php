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
use Illuminate\Support\Facades\Schema;
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
                ->with(['business:id,name', 'permissions:id,key,name,group'])
                ->orderByRaw('CASE WHEN business_id IS NULL THEN 0 ELSE 1 END')
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::query()
                ->orderBy('group')
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'group']),
            'businesses' => Business::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $this->validateRole($request);

        $role = Role::query()->create([
            'business_id' => $data['scope'] === 'tenant' ? $data['business_id'] : null,
            'name' => $data['name'],
            'key' => $data['key'],
            'is_system' => false,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->syncRolePermissions($role, $data['permissions'] ?? []);

        return back()->with('success', 'Rol creado.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validateRole($request, $role);

        $payload = [
            'name' => $data['name'],
        ];

        if (! $role->is_system) {
            $payload['key'] = $data['key'];
            $payload['business_id'] = $data['scope'] === 'tenant' ? $data['business_id'] : null;
            $payload['is_active'] = (bool) ($data['is_active'] ?? true);
        }

        $role->update($payload);
        $this->syncRolePermissions($role, $data['permissions'] ?? []);

        return back()->with('success', 'Rol actualizado.');
    }

    public function destroyRole(Role $role): RedirectResponse
    {
        abort_if($role->is_system, 422, 'No se puede eliminar un rol del sistema.');
        abort_if($role->users()->exists(), 422, 'No se puede eliminar un rol asignado a usuarios.');

        $role->permissions()->detach();
        $role->delete();

        return back()->with('success', 'Rol eliminado.');
    }

    public function permissions(): Response
    {
        Permissions::syncDefaults();

        return Inertia::render('SuperAdmin/Security/Permissions', [
            'permissions' => Permission::query()
                ->withCount(['roles', 'users'])
                ->orderBy('group')
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'group', 'description', 'is_system']),
        ]);
    }

    public function storePermission(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9_.-]+$/', Rule::unique('permissions', 'key')],
            'name' => ['required', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        Permission::query()->create([
            ...$data,
            'is_system' => false,
        ]);

        return back()->with('success', 'Permiso creado.');
    }

    public function updatePermission(Request $request, Permission $permission): RedirectResponse
    {
        $data = $request->validate([
            'key' => [
                'required',
                'string',
                'max:150',
                'regex:/^[a-z0-9_.-]+$/',
                Rule::unique('permissions', 'key')->ignore($permission),
            ],
            'name' => ['required', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($permission->is_system) {
            unset($data['key']);
        }

        $permission->update($data);

        return back()->with('success', 'Permiso actualizado.');
    }

    public function destroyPermission(Permission $permission): RedirectResponse
    {
        abort_if($permission->is_system, 422, 'No se puede eliminar un permiso del sistema.');
        abort_if($permission->roles()->exists() || $permission->users()->exists(), 422, 'No se puede eliminar un permiso en uso.');

        $permission->delete();

        return back()->with('success', 'Permiso eliminado.');
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
                    ->with(['roles:id,business_id,key,name', 'directPermissions:id,key,name'])
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'role', 'business_id'])
                : [],
            'roles' => Permissions::globalAndTenantRoles($businessId)
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'key' => $role->key,
                    'name' => $role->name,
                    'scope' => $role->business_id ? 'tenant' : 'global',
                    'business_name' => $role->business?->name,
                ])
                ->values(),
            'permissions' => Permission::query()->orderBy('group')->orderBy('name')->get(['id', 'key', 'name', 'group']),
        ]);
    }

    public function updateAssignment(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $roleIds = Role::query()
            ->whereIn('id', $data['role_ids'] ?? [])
            ->where(function ($query) use ($user) {
                $query->whereNull('business_id');

                if ($user->business_id) {
                    $query->orWhere('business_id', $user->business_id);
                }
            })
            ->when(Schema::hasColumn('roles', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->pluck('id')
            ->all();

        $user->roles()->sync($roleIds);

        $firstRole = Role::query()->whereIn('id', $roleIds)->orderBy('id')->first();
        if ($firstRole) {
            $user->forceFill(['role' => $firstRole->key])->save();
        }

        $user->directPermissions()->sync($data['permission_ids'] ?? []);

        return back()->with('success', 'Asignación actualizada.');
    }

    private function validateRole(Request $request, ?Role $role = null): array
    {
        $scope = $request->input('scope', $role?->business_id ? 'tenant' : 'global');
        $businessId = $scope === 'tenant' ? $request->integer('business_id') : null;

        return $request->validate([
            'scope' => ['required', Rule::in(['global', 'tenant'])],
            'business_id' => [
                Rule::requiredIf($scope === 'tenant'),
                'nullable',
                'integer',
                Rule::exists('businesses', 'id'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_.-]+$/',
                Rule::unique('roles', 'key')
                    ->where(fn ($query) => $businessId ? $query->where('business_id', $businessId) : $query->whereNull('business_id'))
                    ->ignore($role),
            ],
            'is_active' => ['boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);
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
