<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\RouteDeliveryRun;
use App\Models\RouteDeliveryStop;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Routes\RouteDeliveryRunService;

final class RouteDeliveryRunMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $branch = $context->branch;

        if (! $branch) {
            return $this->withoutCurrentRun();
        }

        $base = RouteDeliveryRun::query()
            ->where('business_id', $context->business->id)
            ->where('branch_id', $branch->id)
            ->where('delivery_user_id', $context->user->id);

        $run = (clone $base)->where('status', 'open')
            ->orderByRaw('started_at IS NULL, started_at')
            ->orderBy('id')
            ->first()
            ?? (clone $base)->where('status', 'draft')->oldest('id')->first();

        if (! $run) {
            return $this->withoutCurrentRun();
        }

        $progress = app(RouteDeliveryRunService::class)->progress($run);
        $isOpen = $run->status === 'open';
        $nextStop = $isOpen ? RouteDeliveryStop::query()
            ->where('business_id', $context->business->id)
            ->where('branch_id', $branch->id)
            ->where('route_delivery_run_id', $run->id)
            ->where('status', 'pending')
            ->with('sale:id,business_number,total')
            ->orderByRaw('position IS NULL, position')
            ->orderBy('id')
            ->first() : null;

        return [
            'has_current_run' => true,
            'run' => ['id' => $run->id, 'status' => $run->status],
            'progress' => [
                'assigned' => $progress['assigned'],
                'pending' => $progress['pending'],
                'delivered' => $progress['delivered'],
                'not_delivered' => $progress['not_delivered'],
                'unpaid_delivered_count' => $progress['unpaid_delivered_count'],
                'unpaid_delivered_amount' => number_format($progress['unpaid_delivered_amount'], 2, '.', ''),
            ],
            'next_stop' => $nextStop ? [
                'id' => $nextStop->id,
                'position' => $nextStop->position,
                'customer_name' => $nextStop->customer_name_snapshot,
                'customer_address' => $nextStop->customer_address_snapshot,
                'customer_phone' => $nextStop->customer_phone_snapshot,
                'sale_number' => $nextStop->sale?->business_number,
                'sale_total' => $nextStop->sale ? number_format((float) $nextStop->sale->total, 2, '.', '') : null,
                'href' => route('routes.delivery-stops.show', $nextStop, absolute: false),
            ] : null,
            'primary_action' => [
                'label' => $isOpen ? 'Continuar entregas' : 'Ver jornada',
                'href' => route('routes.delivery-runs.show', $run, absolute: false),
            ],
        ];
    }

    private function withoutCurrentRun(): array
    {
        return [
            'has_current_run' => false,
            'message' => 'No tienes una jornada de entrega activa o pendiente.',
            'primary_action' => [
                'label' => 'Ver mis entregas',
                'href' => route('routes.delivery-runs.index', absolute: false),
            ],
        ];
    }
}
