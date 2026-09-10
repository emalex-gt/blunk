<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryBatchPreSale;

class ExternalDeliveryEligibility
{
    public function forEntry(RouteDeliveryBatchPreSale $entry): array
    {
        $batch = $entry->batch;

        if ($batch?->delivery_tracking_snapshot !== 'external') {
            return ['eligible' => false, 'responsibility' => null, 'reason' => 'review_required'];
        }

        $responsibility = $batch->collection_responsibility_snapshot;
        if ($responsibility === null) {
            $hasPreSalePayment = $entry->sale?->capturedPayments()->whereNotNull('route_pre_sale_collection_id')->exists() ?? false;
            $hasAnyPayment = $entry->sale?->capturedPayments()->exists() ?? false;
            if ($hasPreSalePayment) {
                $responsibility = 'pre_seller';
            } elseif ($entry->sale?->payment_status === 'unpaid' && ! $hasAnyPayment) {
                $responsibility = 'delivery_agent';
            }
        }
        if (! in_array($responsibility, ['pre_seller', 'delivery_agent'], true)) {
            return ['eligible' => false, 'responsibility' => null, 'reason' => 'review_required'];
        }

        return ['eligible' => true, 'responsibility' => $responsibility, 'reason' => null];
    }
}
