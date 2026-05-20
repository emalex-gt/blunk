<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenantUserController extends Controller
{
    public function index(Business $business): Response
    {
        return Inertia::render('SuperAdmin/Tenants/Users', [
            'tenant' => $business,
            'users' => $business->users()->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }

    public function store(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['owner', 'admin', 'cashier', 'stock_manager'])],
        ]);

        $business->users()->create([
            ...$data,
            'is_super_admin' => false,
            'is_active' => true,
        ]);

        return back();
    }

    public function update(Request $request, Business $business, User $user): RedirectResponse
    {
        abort_unless($user->business_id === $business->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::in(['owner', 'admin', 'cashier', 'stock_manager'])],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return back();
    }

    public function destroy(Business $business, User $user): RedirectResponse
    {
        abort_unless($user->business_id === $business->id, 404);
        $user->delete();

        return back();
    }
}
