<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\OperationDraft;
use App\Models\Sale;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\CashRegister;
use App\Support\Permissions;

final class CashierWorkspaceMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $branchId = $context->branch?->id;
        $canViewCash = $context->hasModule('cash_register') && $context->hasPermission(Permissions::CASH_REGISTER_VIEW);
        $session = $canViewCash && $branchId
            ? CashRegister::currentOpenSession((int) $context->business->id, branchId: (int) $branchId)
            : null;
        $salesPeriod = $session ? 'current_session' : 'today';
        $start = $session?->opened_at ?? $period->start;
        $end = now();
        $sales = Sale::query()
            ->where('business_id', $context->business->id)
            ->when($branchId, fn ($query, int $id) => $query->where('branch_id', $id))
            ->where('created_by', $context->user->id)
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$start, $end]);
        $summary = (clone $sales)->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total), 0) as sales_total')->first();
        $count = (int) ($summary->sales_count ?? 0);
        $total = (float) ($summary->sales_total ?? 0);

        $lastSale = (clone $sales)->latest('created_at')->first(['created_at']);

        return [
            'cash_session' => $canViewCash ? ($session ? [
                'status' => 'open',
                'id' => $session->id,
                'opened_at' => $session->opened_at?->toIso8601String(),
            ] : ['status' => 'closed']) : null,
            'sales_period' => $salesPeriod,
            'sales_count' => $count,
            'sales_total' => number_format($total, 2, '.', ''),
            'average_ticket' => number_format($count ? $total / $count : 0, 2, '.', ''),
            'last_sale_time' => $lastSale?->created_at?->timezone($context->timezone)->format('H:i'),
            'latest_sales' => (clone $sales)->latest('created_at')->limit(5)->get(['id', 'business_number', 'total', 'created_at'])
                ->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'business_number' => $sale->business_number,
                    'total' => number_format((float) $sale->total, 2, '.', ''),
                    'created_at' => $sale->created_at?->timezone($context->timezone)->format('H:i'),
                ])->values()->all(),
            'active_pos_drafts_count' => OperationDraft::query()
                ->where('business_id', $context->business->id)
                ->when($branchId, fn ($query, int $id) => $query->where('branch_id', $id))
                ->where('user_id', $context->user->id)
                ->where('type', OperationDraft::TYPE_POS_SALE)
                ->where('status', OperationDraft::STATUS_ACTIVE)
                ->count(),
            'primary_action' => ['label' => 'Nueva venta', 'href' => route('sales.create', absolute: false)],
            'cash_action' => $canViewCash ? ['label' => 'Ver caja', 'href' => route('cash-register.index', absolute: false)] : null,
        ];
    }
}
