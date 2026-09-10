<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryStop;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RoutePendingCollectionCase;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RoutePendingCollectionEligibility
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(int $businessId, int $branchId, array $filters = []): Collection
    {
        $external = RouteExternalDeliveryReconciliationItem::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('delivery_status', 'delivered')
            ->where('delivery_tracking_snapshot', 'external')
            ->where('collection_responsibility_snapshot', 'delivery_agent')
            ->whereHas('sale', fn ($query) => $this->eligibleSale($query))
            ->with(['sale:id,business_id,branch_id,customer_id,customer_name,total,payment_status,amount_paid,is_credit_sale,credit_balance', 'sale.customer:id,name'])
            ->get()
            ->map(fn (RouteExternalDeliveryReconciliationItem $item) => [
                'sale_id' => $item->sale_id,
                'pre_sale_id' => $item->pre_sale_id,
                'batch_pre_sale_id' => $item->route_delivery_batch_pre_sale_id,
                'business_id' => $item->business_id,
                'branch_id' => $item->branch_id,
                'origin' => 'external_reconciliation',
                'origin_id' => $item->id,
                'delivered_at' => $item->reconciled_at?->toIso8601String(),
                'original_delivery_user_id' => $item->reconciled_by,
                'amount' => (float) $item->sale->total,
                'customer_name' => $item->sale->customer?->name ?? $item->sale->customer_name,
                'assigned_to' => null,
            ]);

        $inApp = RouteDeliveryStop::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('status', 'delivered')
            ->where('delivery_tracking_snapshot', 'in_app')
            ->where('collection_responsibility_snapshot', 'delivery_agent')
            ->whereHas('run')
            ->whereHas('sale', fn ($query) => $this->eligibleSale($query))
            ->with([
                'run:id,delivery_user_id',
                'sale:id,business_id,branch_id,customer_id,customer_name,total,payment_status,amount_paid,is_credit_sale,credit_balance',
                'sale.customer:id,name',
            ])
            ->get()
            ->map(fn (RouteDeliveryStop $stop) => [
                'sale_id' => $stop->sale_id,
                'pre_sale_id' => $stop->pre_sale_id,
                'batch_pre_sale_id' => $stop->route_delivery_batch_pre_sale_id,
                'business_id' => $stop->business_id,
                'branch_id' => $stop->branch_id,
                'origin' => 'in_app_stop',
                'origin_id' => $stop->id,
                'delivered_at' => $stop->completed_at?->toIso8601String(),
                'original_delivery_user_id' => $stop->run?->delivery_user_id,
                'amount' => (float) $stop->sale->total,
                'customer_name' => $stop->sale->customer?->name ?? $stop->sale->customer_name,
                'assigned_to' => null,
            ]);

        return $this->filterRows($external
            ->concat($inApp)
            ->sortByDesc('delivered_at')
            ->values(), $filters);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function queue(int $businessId, int $branchId, array $filters = []): Collection
    {
        $cases = RoutePendingCollectionCase::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->with([
                'sale:id,business_id,branch_id,customer_id,customer_name,total,payment_status,amount_paid,is_credit_sale,credit_balance',
                'sale.customer:id,name',
                'sale.capturedPayments:id,sale_id,status',
                'sale.activeRouteDeliveryCollection:id,sale_id,status',
                'reconciliationItem',
                'stop.run:id,delivery_user_id',
            ])
            ->get()
            ->filter(fn (RoutePendingCollectionCase $case) => $this->eligiblePersistedCase($case))
            ->map(fn (RoutePendingCollectionCase $case) => [
                ...$this->casePayload($case),
                'case_id' => $case->id,
                'is_persisted' => true,
            ]);

        $existingSales = RoutePendingCollectionCase::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->pluck('sale_id')
            ->all();
        $derived = $this->candidates($businessId, $branchId)
            ->reject(fn (array $candidate) => in_array($candidate['sale_id'], $existingSales, true))
            ->map(fn (array $candidate) => [
                ...$candidate,
                'case_id' => null,
                'is_persisted' => false,
            ]);

        return $this->filterRows($cases
            ->concat($derived)
            ->sortByDesc('delivered_at')
            ->values(), $filters);
    }

    private function eligibleSale($query): void
    {
        $query
            ->where('payment_status', 'unpaid')
            ->where('amount_paid', 0)
            ->where('is_credit_sale', false)
            ->where('credit_balance', 0)
            ->whereDoesntHave('capturedPayments')
            ->whereDoesntHave('activeRouteDeliveryCollection');
    }

    private function eligiblePersistedCase(RoutePendingCollectionCase $case): bool
    {
        if (! $case->sale || $case->sale->capturedPayments->isNotEmpty() || $case->sale->activeRouteDeliveryCollection) {
            return false;
        }

        if (
            $case->sale->payment_status !== 'unpaid'
            || (float) $case->sale->amount_paid !== 0.0
            || $case->sale->is_credit_sale
            || (float) $case->sale->credit_balance !== 0.0
        ) {
            return false;
        }

        if ($case->delivery_origin === 'external_reconciliation') {
            return $case->reconciliationItem
                && $case->reconciliationItem->delivery_tracking_snapshot === 'external'
                && $case->reconciliationItem->collection_responsibility_snapshot === 'delivery_agent'
                && $case->reconciliationItem->delivery_status === 'delivered';
        }

        return $case->delivery_origin === 'in_app_stop'
            && $case->stop
            && $case->stop->delivery_tracking_snapshot === 'in_app'
            && $case->stop->collection_responsibility_snapshot === 'delivery_agent'
            && $case->stop->status === 'delivered'
            && $case->stop->run;
    }

    /** @return array<string, mixed> */
    private function casePayload(RoutePendingCollectionCase $case): array
    {
        $external = $case->delivery_origin === 'external_reconciliation';
        $source = $external ? $case->reconciliationItem : $case->stop;

        return [
            'sale_id' => $case->sale_id,
            'pre_sale_id' => $case->pre_sale_id,
            'batch_pre_sale_id' => $case->route_delivery_batch_pre_sale_id,
            'business_id' => $case->business_id,
            'branch_id' => $case->branch_id,
            'origin' => $case->delivery_origin,
            'origin_id' => $external ? $case->route_external_delivery_reconciliation_item_id : $case->route_delivery_stop_id,
            'delivered_at' => $external ? $source?->reconciled_at?->toIso8601String() : $source?->completed_at?->toIso8601String(),
            'original_delivery_user_id' => $case->original_delivery_user_id,
            'amount' => (float) $case->sale->total,
            'customer_name' => $case->sale->customer?->name ?? $case->sale->customer_name,
            'assigned_to' => $case->assigned_to,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows
     *  @return Collection<int, array<string, mixed>>
     */
    private function filterRows(Collection $rows, array $filters): Collection
    {
        $origin = $filters['origin'] ?? null;
        $aging = $filters['aging'] ?? null;
        $assignedTo = isset($filters['assigned_to']) && $filters['assigned_to'] !== '' ? (int) $filters['assigned_to'] : null;
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return $rows
            ->map(function (array $row) {
                $deliveredAt = filled($row['delivered_at'] ?? null) ? Carbon::parse($row['delivered_at']) : null;
                $row['aging'] = $this->aging($deliveredAt);
                return $row;
            })
            ->filter(function (array $row) use ($origin, $aging, $assignedTo, $search) {
                if (filled($origin) && $row['origin'] !== $origin) return false;
                if (filled($aging) && $row['aging'] !== $aging) return false;
                if ($assignedTo !== null && (int) ($row['assigned_to'] ?? 0) !== $assignedTo) return false;
                if ($search === '') return true;

                return str_contains(mb_strtolower((string) ($row['customer_name'] ?? '')), $search)
                    || str_contains((string) ($row['sale_id'] ?? ''), $search)
                    || str_contains((string) ($row['batch_pre_sale_id'] ?? ''), $search);
            })
            ->values();
    }

    private function aging(?Carbon $deliveredAt): string
    {
        $days = $deliveredAt?->startOfDay()->diffInDays(now()->startOfDay()) ?? 0;
        return match (true) {
            $days <= 0 => 'today',
            $days <= 3 => '1_3',
            $days <= 7 => '4_7',
            default => '8_plus',
        };
    }
}
