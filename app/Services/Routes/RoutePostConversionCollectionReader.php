<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RoutePostConversionCollection;
use App\Models\User;
use App\Support\CashRegister;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RoutePostConversionCollectionReader
{
    public function __construct(private readonly RouteBranchCollectionSettingsService $branchCollectionSettings)
    {
    }

    /** @return Collection<int, array<string, mixed>> */
    public function pendingForSeller(User $actor): Collection
    {
        return RouteDeliveryBatchPreSale::query()
            ->whereHas('batch', fn ($query) => $query
                ->where('business_id', $actor->business_id)
                ->where('branch_id', $actor->current_branch_id)
                ->where('collection_workflow_mode_snapshot', RouteBranchCollectionSettingsService::WORKFLOW_PER_ORDER_COLLECTION)
                ->where('collection_responsibility_snapshot', 'pre_seller')
                ->whereHas('workDay', fn ($workDays) => $workDays->where('seller_id', $actor->id)))
            ->whereHas('preSale', fn ($query) => $query->where('business_id', $actor->business_id)->where('branch_id', $actor->current_branch_id)->where('seller_id', $actor->id))
            ->whereHas('sale', fn ($query) => $query
                ->where('business_id', $actor->business_id)
                ->where('branch_id', $actor->current_branch_id)
                ->where('payment_status', 'unpaid')
                ->where('amount_paid', 0)
                ->where('credit_balance', 0)
                ->where('is_credit_sale', false)
                ->where('status', '!=', 'cancelled'))
            ->whereDoesntHave('sale.routePostConversionCollections', fn ($query) => $query->captured())
            ->with([
                'batch:id,business_id,branch_id,route_work_day_id,collection_workflow_mode_snapshot,collection_responsibility_snapshot',
                'batch.workDay:id,seller_id,work_date',
                'preSale:id,customer_id,converted_sale_id,agreed_payment_method,route_work_day_id,seller_id,total',
                'preSale.customer:id,name,commercial_name',
                'sale:id,business_id,branch_id,total,payment_status,amount_paid,credit_balance,is_credit_sale',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (RouteDeliveryBatchPreSale $entry) => $this->pendingPayload($entry));
    }

    /** @return array<string, mixed>|null */
    public function contextForPreSale(PreSale $preSale, User $actor): ?array
    {
        $entry = RouteDeliveryBatchPreSale::query()
            ->where('pre_sale_id', $preSale->id)
            ->whereHas('batch', fn ($query) => $query
                ->where('business_id', $preSale->business_id)
                ->where('branch_id', $preSale->branch_id)
                ->where('collection_workflow_mode_snapshot', RouteBranchCollectionSettingsService::WORKFLOW_PER_ORDER_COLLECTION)
                ->where('collection_responsibility_snapshot', 'pre_seller'))
            ->with([
                'batch:id,business_id,branch_id,route_work_day_id,collection_workflow_mode_snapshot,collection_responsibility_snapshot',
                'batch.workDay:id,seller_id,work_date',
                'preSale.customer:id,name,commercial_name',
                'sale:id,business_id,branch_id,total,payment_status,amount_paid,credit_balance,is_credit_sale,payment_method,status',
                'sale.routePostConversionCollections' => fn ($query) => $query
                    ->with(['collectedBy:id,name', 'recordedBy:id,name', 'reversal.reversedBy:id,name', 'cashSession:id,status'])
                    ->orderByDesc('id'),
            ])
            ->first();

        if (! $entry || ! $entry->sale) {
            return null;
        }

        $collections = $entry->sale->routePostConversionCollections;
        if ($entry->sale->payment_status === 'paid' && $collections->isEmpty()) {
            return null;
        }

        $policy = $this->displayPolicy((int) $preSale->business_id, (int) $preSale->branch_id);
        $active = $collections->firstWhere('status', 'captured');
        $history = $collections->map(fn (RoutePostConversionCollection $collection) => [
            'id' => $collection->id,
            'status' => $collection->status,
            'amount' => (float) $collection->amount,
            'payment_method' => $collection->payment_method,
            'collected_by' => $collection->collectedBy ? ['id' => $collection->collectedBy->id, 'name' => $collection->collectedBy->name] : null,
            'recorded_by' => $collection->recordedBy ? ['id' => $collection->recordedBy->id, 'name' => $collection->recordedBy->name] : null,
            'collected_at' => $collection->collected_at?->toIso8601String(),
            'reversal' => $collection->reversal ? [
                'reason_code' => $collection->reversal->reason_code,
                'explanation' => $collection->reversal->explanation,
                'reversed_by' => $collection->reversal->reversedBy?->name,
                'reversed_at' => $collection->reversal->reversed_at?->toIso8601String(),
            ] : null,
        ])->values();

        return [
            'entry_id' => $entry->id,
            'eligible' => $this->isUnpaidOperationalSale($entry),
            'sale_id' => $entry->sale_id,
            'total' => (float) $entry->sale->total,
            'agreed_method' => $entry->agreed_payment_method_snapshot,
            'payment_policy' => $policy,
            'active_collection' => $active ? $this->activePayload($active) : null,
            'history' => $history,
            'reversal_context' => $active ? $this->reversalContext($active) : null,
        ];
    }

    /** @return array{available:bool,allowed_methods:array<int,string>,reason:?string} */
    public function displayPolicy(int $businessId, int $branchId): array
    {
        try {
            $policy = $this->branchCollectionSettings->validatedStoredPolicy($this->branchCollectionSettings->forBranch($businessId, $branchId));
        } catch (ValidationException) {
            $policy = null;
        }

        return $policy
            ? ['available' => true, 'allowed_methods' => $policy['allowed_payment_methods'], 'reason' => null]
            : ['available' => false, 'allowed_methods' => [], 'reason' => 'La política de cobro vigente de la sucursal no está disponible.'];
    }

    /** @return array<string, mixed> */
    private function pendingPayload(RouteDeliveryBatchPreSale $entry): array
    {
        $preSale = $entry->preSale;
        $sale = $entry->sale;

        return [
            'entry_id' => $entry->id,
            'pre_sale_id' => $entry->pre_sale_id,
            'sale_id' => $entry->sale_id,
            'reference' => "Preventa #{$entry->pre_sale_id}",
            'customer' => $preSale?->customer ? ['name' => $preSale->customer->commercial_name ?: $preSale->customer->name] : null,
            'total' => (float) $sale->total,
            'agreed_method' => $entry->agreed_payment_method_snapshot,
            'work_date' => $entry->batch?->workDay?->work_date?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function activePayload(RoutePostConversionCollection $collection): array
    {
        return [
            'id' => $collection->id,
            'amount' => (float) $collection->amount,
            'payment_method' => $collection->payment_method,
            'custody_status' => $collection->custody_status,
            'collected_by' => $collection->collectedBy ? ['id' => $collection->collectedBy->id, 'name' => $collection->collectedBy->name] : null,
            'recorded_by' => $collection->recordedBy ? ['id' => $collection->recordedBy->id, 'name' => $collection->recordedBy->name] : null,
            'collected_at' => $collection->collected_at?->toIso8601String(),
        ];
    }

    /** @return array{state:string,available:bool,message:string,requires_cash_adjustment_confirmation:bool} */
    private function reversalContext(RoutePostConversionCollection $collection): array
    {
        $blockedBySettlement = RouteCashSettlementItem::query()
            ->where('route_post_conversion_collection_id', $collection->id)
            ->where('is_active', true)
            ->whereHas('settlement', fn ($query) => $query->whereIn('status', ['draft', 'confirmed']))
            ->exists();
        if ($blockedBySettlement) return ['state' => 'settlement_blocked', 'available' => false, 'message' => 'El cobro forma parte de una liquidación activa y no puede revertirse.', 'requires_cash_adjustment_confirmation' => false];
        if ($collection->payment_method !== 'cash') return ['state' => 'non_cash', 'available' => true, 'message' => 'La venta volverá a quedar pendiente de cobro.', 'requires_cash_adjustment_confirmation' => false];
        if ($collection->custody_status === 'held_by_collector') return ['state' => 'held_cash', 'available' => true, 'message' => 'El efectivo dejará de estar pendiente de liquidación.', 'requires_cash_adjustment_confirmation' => false];

        $session = $collection->cashSession;
        if (! $session || $session->status === 'closed') return ['state' => 'historical_closed_session_ledger', 'available' => true, 'message' => 'La reversa quedará registrada sin modificar una caja posterior.', 'requires_cash_adjustment_confirmation' => false];
        $current = CashRegister::currentOpenSession((int) $collection->business_id, true, (int) $collection->branch_id);
        if (! $current || (int) $current->id !== (int) $session->id) return ['state' => 'open_non_current_blocked', 'available' => false, 'message' => 'Esta reversa no está disponible porque la caja original sigue abierta pero ya no es la caja actual.', 'requires_cash_adjustment_confirmation' => false];

        return ['state' => 'current_open_session_adjustment', 'available' => true, 'message' => 'Se registrará un ajuste negativo en la misma caja.', 'requires_cash_adjustment_confirmation' => true];
    }

    private function isUnpaidOperationalSale(RouteDeliveryBatchPreSale $entry): bool
    {
        $sale = $entry->sale;
        return $sale && $sale->payment_status === 'unpaid' && (float) $sale->amount_paid === 0.0 && (float) $sale->credit_balance === 0.0 && ! $sale->is_credit_sale && $sale->status !== 'cancelled';
    }
}
