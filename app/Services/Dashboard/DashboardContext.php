<?php

namespace App\Services\Dashboard;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;

final class DashboardContext
{
    /**
     * @param array<int, string> $permissions
     * @param array<int, string> $modules
     */
    public function __construct(
        public readonly Business $business,
        public readonly User $user,
        public readonly ?Branch $branch,
        public readonly bool $branchScoped,
        public readonly array $permissions,
        public readonly array $modules,
        public readonly string $timezone,
    ) {
    }

    public static function forUser(Business $business, User $user): self
    {
        $branchScoped = BranchInventory::branchesEnabled((int) $business->id);

        return new self(
            business: $business,
            user: $user,
            branch: BranchInventory::activeBranch((int) $business->id),
            branchScoped: $branchScoped,
            permissions: Permissions::forUser($user),
            modules: enabled_modules((int) $business->id),
            timezone: tenantTimezone($business),
        );
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules, true);
    }

    public function period(string $key): DashboardPeriod
    {
        return DashboardPeriod::for($key, $this->timezone);
    }

    public function branchIdForScopedMetric(): ?int
    {
        return $this->branchScoped ? $this->branch?->id : null;
    }

    public function payload(): array
    {
        return [
            'timezone' => $this->timezone,
            'branch' => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ] : null,
            'branch_scoped' => $this->branchScoped,
        ];
    }
}
