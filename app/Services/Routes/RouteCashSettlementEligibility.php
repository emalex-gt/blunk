<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryCollection;
use App\Models\RoutePreSaleCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RouteCashSettlementEligibility
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forCollector(int $businessId, int $branchId, int $collectorId): Collection
    {
        $preSaleCollections = $this->eligiblePreSaleCollections($businessId, $branchId, $collectorId);
        $deliveryCollections = $this->eligibleDeliveryCollections($businessId, $branchId, $collectorId);

        return $preSaleCollections
            ->concat($deliveryCollections)
            ->sortBy(fn (array $collection) => sprintf('%s|%s|%010d', $collection['collected_at'], $collection['origin'], $collection['collection_id']))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function eligiblePreSaleCollections(int $businessId, int $branchId, int $collectorId): Collection
    {
        $query = RoutePreSaleCollection::query()
            ->leftJoin('pre_sales', 'pre_sales.id', '=', 'route_pre_sale_collections.pre_sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'pre_sales.customer_id')
            ->select([
                'route_pre_sale_collections.id as collection_id', 'route_pre_sale_collections.business_id', 'route_pre_sale_collections.branch_id',
                'route_pre_sale_collections.collected_by as collector_user_id', 'route_pre_sale_collections.amount', 'route_pre_sale_collections.payment_method',
                'route_pre_sale_collections.custody_status', 'route_pre_sale_collections.collected_at', 'route_pre_sale_collections.route_work_day_id',
                'pre_sales.id as pre_sale_id', 'customers.name as customer_name', 'customers.commercial_name as customer_commercial_name',
            ])
            ->where('route_pre_sale_collections.business_id', $businessId)
            ->where('route_pre_sale_collections.branch_id', $branchId)
            ->where('route_pre_sale_collections.collected_by', $collectorId)
            ->where('route_pre_sale_collections.payment_method', 'cash')
            ->where('route_pre_sale_collections.custody_status', 'held_by_collector');

        $this->excludeActiveSettlementReservations($query, 'route_pre_sale_collection_id');

        return $query->get()->map(fn (RoutePreSaleCollection $collection) => [
            'origin' => 'route_pre_sale_collection',
            'collection_id' => (int) $collection->collection_id,
            'business_id' => (int) $collection->business_id,
            'branch_id' => (int) $collection->branch_id,
            'collector_user_id' => (int) $collection->collector_user_id,
            'amount' => $collection->amount,
            'payment_method' => $collection->payment_method,
            'custody_status' => $collection->custody_status,
            'collected_at' => $collection->collected_at,
            'customer_name' => $collection->customer_commercial_name ?: $collection->customer_name,
            'pre_sale_id' => $collection->pre_sale_id ? (int) $collection->pre_sale_id : null,
            'route_work_day_id' => $collection->route_work_day_id ? (int) $collection->route_work_day_id : null,
            'route_delivery_run_id' => null,
            'route_delivery_batch_id' => null,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function eligibleDeliveryCollections(int $businessId, int $branchId, int $collectorId): Collection
    {
        $query = RouteDeliveryCollection::query()
            ->leftJoin('sales', 'sales.id', '=', 'route_delivery_collections.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('route_delivery_stops', 'route_delivery_stops.id', '=', 'route_delivery_collections.route_delivery_stop_id')
            ->select([
                'route_delivery_collections.id as collection_id', 'route_delivery_collections.business_id', 'route_delivery_collections.branch_id',
                'route_delivery_collections.collected_by as collector_user_id', 'route_delivery_collections.amount', 'route_delivery_collections.payment_method',
                'route_delivery_collections.custody_status', 'route_delivery_collections.collected_at', 'route_delivery_collections.sale_id', 'route_delivery_collections.pre_sale_id',
                'customers.name as customer_name', 'customers.commercial_name as customer_commercial_name', 'route_delivery_stops.route_delivery_run_id', 'route_delivery_stops.route_delivery_batch_id',
            ])
            ->where('route_delivery_collections.business_id', $businessId)
            ->where('route_delivery_collections.branch_id', $branchId)
            ->where('route_delivery_collections.collected_by', $collectorId)
            ->where('route_delivery_collections.payment_method', 'cash')
            ->where('route_delivery_collections.custody_status', 'held_by_collector');

        $this->excludeActiveSettlementReservations($query, 'route_delivery_collection_id');

        return $query->get()->map(fn (RouteDeliveryCollection $collection) => [
            'origin' => 'route_delivery_collection',
            'collection_id' => (int) $collection->collection_id,
            'business_id' => (int) $collection->business_id,
            'branch_id' => (int) $collection->branch_id,
            'collector_user_id' => (int) $collection->collector_user_id,
            'amount' => $collection->amount,
            'payment_method' => $collection->payment_method,
            'custody_status' => $collection->custody_status,
            'collected_at' => $collection->collected_at,
            'customer_name' => $collection->customer_commercial_name ?: $collection->customer_name,
            'sale_id' => $collection->sale_id ? (int) $collection->sale_id : null,
            'pre_sale_id' => $collection->pre_sale_id ? (int) $collection->pre_sale_id : null,
            'route_work_day_id' => null,
            'route_delivery_run_id' => $collection->route_delivery_run_id ? (int) $collection->route_delivery_run_id : null,
            'route_delivery_batch_id' => $collection->route_delivery_batch_id ? (int) $collection->route_delivery_batch_id : null,
        ]);
    }

    private function excludeActiveSettlementReservations(Builder $query, string $originColumn): void
    {
        if (! Schema::hasTable('route_cash_settlement_items') || ! Schema::hasTable('route_cash_settlements')) {
            return;
        }

        $table = $query->getModel()->getTable();

        $query->whereNotExists(function ($reservation) use ($table, $originColumn) {
            $reservation
                ->selectRaw('1')
                ->from('route_cash_settlement_items as settlement_items')
                ->join('route_cash_settlements as settlements', 'settlements.id', '=', 'settlement_items.route_cash_settlement_id')
                ->whereColumn("settlement_items.{$originColumn}", "{$table}.id")
                ->where('settlement_items.is_active', true)
                ->whereIn('settlements.status', ['draft', 'confirmed']);
        });
    }
}
