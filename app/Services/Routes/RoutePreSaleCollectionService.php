<?php

namespace App\Services\Routes;

use App\Models\OperationIdempotencyKey;
use App\Models\PreSale;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\CashRegister;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePreSaleCollectionService
{
    private const METHODS = ['cash', 'card', 'transfer', 'check'];

    public function __construct(private readonly RouteCashOperationGuard $cash)
    {
    }

    public function capture(PreSale $preSale, array $data, User $actor): IdempotencyResult
    {
        $businessId = (int) $preSale->business_id;
        $branchId = (int) $preSale->branch_id;
        $key = (string) ($data['idempotency_key'] ?? '');

        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_pre_sale_collection_capture', $key,
            $data,
            function () use ($preSale, $data, $actor, $businessId, $branchId, $key) {
                return DB::transaction(function () use ($preSale, $data, $actor, $businessId, $branchId, $key) {
                    abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
                    $branchPolicy = app(RouteBranchCollectionSettingsService::class)->lockValidatedPolicyForExecution($businessId, $branchId);
                    $locked = PreSale::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($preSale->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($locked->status, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING, PreSale::STATUS_PICKED], true)) {
                        throw ValidationException::withMessages(['pre_sale' => 'La preventa no permite registrar un cobro.']);
                    }
                    if (TenantSetting::query()->where('business_id', $businessId)->value('route_collection_responsibility') === 'delivery_agent') {
                        throw ValidationException::withMessages(['collection' => 'El cobro se registrará durante la entrega.']);
                    }
                    if ($branchPolicy !== null && $branchPolicy['collection_workflow_mode'] !== RouteBranchCollectionSettingsService::WORKFLOW_PER_ORDER_COLLECTION) {
                        throw ValidationException::withMessages(['collection' => 'El cobro previo no está disponible para esta política de sucursal.']);
                    }

                    $isOverride = (int) $locked->seller_id !== (int) $actor->id;
                    if ($isOverride && ! Permissions::userHas($actor, Permissions::ROUTES_COLLECTIONS_OVERRIDE)) {
                        abort(403);
                    }
                    if ($isOverride && blank($data['override_reason'] ?? null)) {
                        throw ValidationException::withMessages(['override_reason' => 'El motivo del override es obligatorio.']);
                    }
                    if ($isOverride && (! isset($data['collected_by']) || ! isset($data['collected_at']))) {
                        throw ValidationException::withMessages(['override' => 'El override requiere cobrador y fecha/hora del cobro.']);
                    }

                    $amount = round((float) ($data['amount'] ?? 0), 2);
                    if ($amount !== round((float) $locked->total, 2)) {
                        throw ValidationException::withMessages(['amount' => 'El cobro debe coincidir exactamente con el total de la preventa.']);
                    }
                    $method = (string) ($data['payment_method'] ?? '');
                    if (! in_array($method, self::METHODS, true)) {
                        throw ValidationException::withMessages(['payment_method' => 'La forma de pago no es válida.']);
                    }
                    if ($branchPolicy !== null && ! in_array($method, $branchPolicy['allowed_payment_methods'], true)) {
                        throw ValidationException::withMessages(['payment_method' => 'La forma de pago no está permitida para esta sucursal.']);
                    }
                    if (RoutePreSaleCollection::query()->where('business_id', $businessId)->where('branch_id', $branchId)->where('pre_sale_id', $locked->id)->whereIn('status', ['captured', 'linked'])->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['collection' => 'La preventa ya tiene un cobro activo.']);
                    }

                    $session = $this->cash->requireOpen($businessId, $branchId, true);
                    $idempotencyId = OperationIdempotencyKey::query()
                        ->where('business_id', $businessId)->where('branch_id', $branchId)->where('user_id', $actor->id)
                        ->where('operation_type', 'route_pre_sale_collection_capture')->where('idempotency_key', $key)->value('id');
                    $policy = TenantSetting::query()->where('business_id', $businessId)->value('route_cash_custody_policy') ?? 'collector_custody_until_settlement';
                    $immediateCash = $method === 'cash' && $policy === 'immediate_branch_register';
                    $collection = RoutePreSaleCollection::query()->create([
                        'business_id' => $businessId, 'branch_id' => $branchId, 'pre_sale_id' => $locked->id,
                        'route_work_day_id' => $locked->route_work_day_id, 'collected_by' => $data['collected_by'] ?? $actor->id,
                        'recorded_by' => $actor->id, 'amount' => $amount, 'payment_method' => $method,
                        'reference' => $data['reference'] ?? null, 'details' => $data['details'] ?? null,
                        'collected_at' => $data['collected_at'] ?? now(), 'status' => 'captured',
                        'custody_status' => $method === 'cash' ? ($immediateCash ? 'posted_to_branch_cash' : 'held_by_collector') : 'not_applicable',
                        'cash_register_session_id' => $session->id, 'operation_idempotency_key_id' => $idempotencyId,
                        'override_reason' => $isOverride ? trim((string) $data['override_reason']) : null,
                    ]);
                    if ($immediateCash) {
                        $movement = CashRegister::recordMovement(
                            $session,
                            'sale_cash',
                            $amount,
                            'route_pre_sale_collection',
                            $collection->id,
                            "Cobro de preventa #{$locked->id}",
                            $actor->id,
                        );
                        $collection->update(['cash_movement_id' => $movement->id]);
                    }

                    return ['result_id' => $collection->id, 'response_payload' => ['collection_id' => $collection->id]];
                });
            },
            'route_pre_sale_collection',
        );
    }
}
