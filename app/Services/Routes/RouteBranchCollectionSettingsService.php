<?php

namespace App\Services\Routes;

use App\Models\Branch;
use App\Models\RouteBranchCollectionSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteBranchCollectionSettingsService
{
    public const WORKFLOW_IMMEDIATE_PAID = 'immediate_paid';
    public const WORKFLOW_PER_ORDER_COLLECTION = 'per_order_collection';

    public const PAYMENT_METHOD_CASH = 'cash';
    public const PAYMENT_METHOD_CARD = 'card';
    public const PAYMENT_METHOD_TRANSFER = 'transfer';
    public const PAYMENT_METHOD_CHECK = 'check';

    /** @var array<int, string> */
    public const WORKFLOWS = [
        self::WORKFLOW_IMMEDIATE_PAID,
        self::WORKFLOW_PER_ORDER_COLLECTION,
    ];

    /** @var array<int, string> */
    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_CASH,
        self::PAYMENT_METHOD_CARD,
        self::PAYMENT_METHOD_TRANSFER,
        self::PAYMENT_METHOD_CHECK,
    ];

    public function forBranch(int $businessId, int $branchId): ?RouteBranchCollectionSetting
    {
        return RouteBranchCollectionSetting::query()
            ->whereHas('branch', fn ($query) => $query
                ->whereKey($branchId)
                ->where('business_id', $businessId))
            ->first();
    }

    /** @return array{collection_workflow_mode:string,allowed_payment_methods:array<int,string>,primary_payment_method:string}|null */
    public function lockValidatedPolicyForExecution(int $businessId, int $branchId): ?array
    {
        $branch = Branch::query()
            ->whereKey($branchId)
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'La sucursal activa no pertenece al negocio actual.',
            ]);
        }

        $setting = RouteBranchCollectionSetting::query()
            ->where('branch_id', $branch->id)
            ->lockForUpdate()
            ->first();

        if (! $setting) {
            return null;
        }

        return $this->validatedStoredPolicy($setting);
    }

    /** @return array{collection_workflow_mode:string,allowed_payment_methods:array<int,string>,primary_payment_method:string}|null */
    public function validatedStoredPolicy(?RouteBranchCollectionSetting $setting): ?array
    {
        if (! $setting) {
            return null;
        }

        try {
            $policy = $this->validatedPolicy([
                'collection_workflow_mode' => $setting->collection_workflow_mode,
                'allowed_payment_methods' => $setting->allowed_payment_methods,
                'primary_payment_method' => $setting->primary_payment_method,
            ]);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'route_collection_policy_invalid' => 'La política de cobro de la sucursal es inválida.',
            ]);
        }

        if ($policy['collection_workflow_mode'] !== $setting->collection_workflow_mode
            || $policy['allowed_payment_methods'] !== $setting->allowed_payment_methods
            || $policy['primary_payment_method'] !== $setting->primary_payment_method) {
            throw ValidationException::withMessages([
                'route_collection_policy_invalid' => 'La política de cobro de la sucursal no está en formato canónico.',
            ]);
        }

        return $policy;
    }

    /** @param array<string, mixed> $policy */
    public function save(int $businessId, Branch $branch, array $policy): RouteBranchCollectionSetting
    {
        return DB::transaction(function () use ($businessId, $branch, $policy) {
            $lockedBranch = Branch::query()
                ->whereKey($branch->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (! $lockedBranch) {
                throw ValidationException::withMessages([
                    'branch_id' => 'La sucursal no pertenece al negocio activo.',
                ]);
            }

            $payload = $this->validatedPolicy($policy);
            $setting = RouteBranchCollectionSetting::query()
                ->where('branch_id', $lockedBranch->id)
                ->lockForUpdate()
                ->first();

            if ($setting) {
                $setting->update($payload);

                return $setting->refresh();
            }

            return RouteBranchCollectionSetting::query()->create([
                'branch_id' => $lockedBranch->id,
                ...$payload,
            ]);
        });
    }

    /** @param array<string, mixed> $policy
     *  @return array{collection_workflow_mode:string,allowed_payment_methods:array<int,string>,primary_payment_method:string}
     */
    private function validatedPolicy(array $policy): array
    {
        $workflow = $policy['collection_workflow_mode'] ?? null;
        $allowedMethods = $policy['allowed_payment_methods'] ?? null;
        $primaryMethod = $policy['primary_payment_method'] ?? null;
        $errors = [];

        if (! is_string($workflow) || ! in_array($workflow, self::WORKFLOWS, true)) {
            $errors['collection_workflow_mode'] = 'El workflow de cobro no es válido.';
        }

        if (! is_array($allowedMethods) || $allowedMethods === []) {
            $errors['allowed_payment_methods'] = 'Debes seleccionar al menos un método de pago.';
        } elseif (count($allowedMethods) !== count(array_unique($allowedMethods, SORT_STRING))
            || collect($allowedMethods)->contains(fn ($method) => ! is_string($method) || ! in_array($method, self::PAYMENT_METHODS, true))) {
            $errors['allowed_payment_methods'] = 'Los métodos de pago permitidos no son válidos o están duplicados.';
        }

        if (! is_string($primaryMethod) || ! in_array($primaryMethod, self::PAYMENT_METHODS, true)) {
            $errors['primary_payment_method'] = 'El método de pago principal no es válido.';
        } elseif (is_array($allowedMethods) && ! in_array($primaryMethod, $allowedMethods, true)) {
            $errors['primary_payment_method'] = 'El método de pago principal debe estar permitido.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'collection_workflow_mode' => $workflow,
            'allowed_payment_methods' => array_values(array_filter(
                self::PAYMENT_METHODS,
                fn (string $method) => in_array($method, $allowedMethods, true),
            )),
            'primary_payment_method' => $primaryMethod,
        ];
    }
}
