<?php

namespace App\Http\Controllers;

use App\Support\BranchInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function activate(Request $request): RedirectResponse
    {
        abort_unless(BranchInventory::branchesEnabled(), 403);

        $data = $request->validate([
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')
                    ->where('business_id', currentBusinessId())
                    ->where('is_active', true),
            ],
        ]);

        if (! (bool) $request->user()?->is_super_admin) {
            abort_unless((int) $request->user()?->current_branch_id === (int) $data['branch_id'], 403);
        }

        BranchInventory::setActiveBranch(currentBusinessId(), (int) $data['branch_id']);

        return back()->with('success', 'Sucursal activa actualizada.');
    }
}
