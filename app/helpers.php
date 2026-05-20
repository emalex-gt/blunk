<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Business;
use App\Models\TenantModule;
use App\Support\BranchInventory;
use App\Support\Currency;

if (! function_exists('currentBusinessId')) {
    function currentBusinessId(): ?int
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->id === 1 && session('active_business_id')) {
            return (int) session('active_business_id');
        }

        return $user->business_id;
    }
}

if (! function_exists('tenantSetting')) {
    function tenantSetting(string $key, mixed $default = null, ?int $businessId = null): mixed
    {
        $businessId ??= currentBusinessId();

        if (! $businessId || ! Schema::hasTable('tenant_settings') || ! Schema::hasColumn('tenant_settings', $key)) {
            return $default;
        }

        $value = DB::table('tenant_settings')
            ->where('business_id', $businessId)
            ->value($key);

        return $value ?? $default;
    }
}

if (! function_exists('module_enabled')) {
    function module_enabled(string $module, ?int $businessId = null): bool
    {
        $businessId ??= currentBusinessId();

        if (! $businessId) {
            return false;
        }

        static $cache = [];

        if (! array_key_exists($businessId, $cache)) {
            if (! Schema::hasTable('tenant_modules')) {
                $cache[$businessId] = [];
            } else {
                $cache[$businessId] = TenantModule::query()
                    ->where('business_id', $businessId)
                    ->where('is_enabled', true)
                    ->pluck('module')
                    ->all();
            }
        }

        return in_array($module, $cache[$businessId], true);
    }
}

if (! function_exists('enabled_modules')) {
    function enabled_modules(?int $businessId = null): array
    {
        $businessId ??= currentBusinessId();

        if (! $businessId || ! Schema::hasTable('tenant_modules')) {
            return [];
        }

        return TenantModule::query()
            ->where('business_id', $businessId)
            ->where('is_enabled', true)
            ->pluck('module')
            ->all();
    }
}

if (! function_exists('branches_enabled')) {
    function branches_enabled(?int $businessId = null): bool
    {
        return BranchInventory::branchesEnabled($businessId);
    }
}

if (! function_exists('active_branch')) {
    function active_branch(?int $businessId = null): ?\App\Models\Branch
    {
        $businessId ??= currentBusinessId();

        return $businessId ? BranchInventory::activeBranch($businessId) : null;
    }
}

if (! function_exists('active_branch_id')) {
    function active_branch_id(?int $businessId = null): ?int
    {
        return active_branch($businessId)?->id;
    }
}

if (! function_exists('tenantTimezone')) {
    function tenantTimezone(Business|int|null $business = null): string
    {
        if (is_int($business)) {
            $business = Business::query()->select('id', 'country')->find($business);
        }

        if (! $business) {
            $businessId = currentBusinessId();
            $business = $businessId
                ? Business::query()->select('id', 'country')->find($businessId)
                : null;
        }

        $country = $business?->country ?: 'GT';

        return config("tenant.timezones.{$country}", 'America/Guatemala');
    }
}

if (! function_exists('formatMoney')) {
    function formatMoney(float|int|string|null $amount, ?string $country = null): string
    {
        return Currency::formatMoney($amount, $country);
    }
}

if (! function_exists('stockMovementNote')) {
    function stockMovementNote(string $type, int|string|null $referenceId = null): string
    {
        return match ($type) {
            'sale', 'venta' => $referenceId ? "Venta #{$referenceId}" : 'Venta',
            'purchase', 'compra' => $referenceId ? "Compra #{$referenceId}" : 'Compra',
            'sale_cancel', 'anulacion_venta' => $referenceId ? "Anulación venta #{$referenceId}" : 'Anulación de venta',
            'initial', 'inicial' => 'Stock inicial',
            'adjustment', 'manual' => 'Ajuste de inventario',
            'entry', 'add', 'in' => 'Entrada manual',
            'exit', 'remove', 'out' => 'Salida manual',
            'transfer_out' => $referenceId ? "Salida traslado #{$referenceId}" : 'Salida por traslado',
            'transfer_in' => $referenceId ? "Entrada traslado #{$referenceId}" : 'Entrada por traslado',
            default => 'Movimiento de stock',
        };
    }
}
