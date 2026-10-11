<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchAccessService
{
    /** @return Collection<int, Branch> */
    public function authorizedBranches(User $user, Business|int $business): Collection
    {
        $businessId = $business instanceof Business ? (int) $business->id : $business;

        if ($user->is_super_admin) {
            return Branch::query()->where('business_id', $businessId)->where('is_active', true)->orderBy('name')->get();
        }

        if ((int) $user->business_id !== $businessId) {
            return collect();
        }

        return Branch::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->when(! $user->all_branches_access, fn ($query) => $query->whereIn('id', $user->branchAccesses()->select('branch_id')))
            ->orderBy('name')
            ->get();
    }

    public function canAccessBranch(User $user, Branch|int $branch, ?int $businessId = null): bool
    {
        $branch = $branch instanceof Branch
            ? $branch
            : Branch::query()->find($branch);

        if (! $branch || ! $branch->is_active || ($businessId !== null && (int) $branch->business_id !== $businessId)) {
            return false;
        }

        return $this->authorizedBranches($user, (int) $branch->business_id)->contains('id', $branch->id);
    }

    public function canAccessAllBranches(User $user, Business|int $business): bool
    {
        $businessId = $business instanceof Business ? (int) $business->id : $business;

        return $user->is_super_admin || ((int) $user->business_id === $businessId && (bool) $user->all_branches_access);
    }

    public function resolveActiveBranch(User $user, int $businessId): Branch
    {
        $authorized = $this->authorizedBranches($user, $businessId);
        abort_if($authorized->isEmpty(), 403, 'No tienes acceso a una sucursal activa.');

        $requestedId = session('active_branch_id') ?: $user->current_branch_id;
        $branch = $requestedId ? $authorized->firstWhere('id', (int) $requestedId) : null;

        if (! $branch) {
            session()->forget(['active_branch_id', 'selected_branch_id']);
            $branch = $authorized->first();
            if ((int) $user->current_branch_id !== (int) $branch->id) {
                $user->forceFill(['current_branch_id' => $branch->id])->save();
            }
        }

        return $branch;
    }

    public function assertCanAccessBranch(User $user, Branch|int $branch, ?int $businessId = null): Branch
    {
        $model = $branch instanceof Branch ? $branch : Branch::query()->findOrFail($branch);
        abort_unless($this->canAccessBranch($user, $model, $businessId), 403);

        return $model;
    }

    /** @param array<int, int> $branchIds */
    public function replaceAccess(User $user, bool $allBranchesAccess, array $branchIds, ?int $preferredBranchId = null): void
    {
        DB::transaction(function () use ($user, $allBranchesAccess, $branchIds, $preferredBranchId): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless(! $lockedUser->is_super_admin && $lockedUser->business_id, 403);

            $ids = collect($branchIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values();
            $selectedBranches = Branch::query()
                ->where('business_id', $lockedUser->business_id)
                ->where('is_active', true)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if (! $allBranchesAccess && ($ids->isEmpty() || $selectedBranches->count() !== $ids->count())) {
                throw ValidationException::withMessages(['branch_ids' => 'Debes seleccionar al menos una sucursal activa autorizable.']);
            }

            $availableBranches = $allBranchesAccess
                ? Branch::query()
                    ->where('business_id', $lockedUser->business_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get()
                : $selectedBranches;

            if ($availableBranches->isEmpty()) {
                throw ValidationException::withMessages(['branch_ids' => 'Debes tener al menos una sucursal activa autorizable.']);
            }

            $lockedUser->branchAccesses()->delete();
            foreach ($selectedBranches as $branch) {
                $lockedUser->branchAccesses()->create([
                    'business_id' => $lockedUser->business_id,
                    'branch_id' => $branch->id,
                ]);
            }

            $currentBranchId = $availableBranches->contains('id', $preferredBranchId)
                ? $preferredBranchId
                : ($availableBranches->contains('id', $lockedUser->current_branch_id)
                    ? $lockedUser->current_branch_id
                    : $availableBranches->first()->id);

            $lockedUser->forceFill([
                'all_branches_access' => $allBranchesAccess,
                'current_branch_id' => $currentBranchId,
            ])->save();
        });
    }
}
