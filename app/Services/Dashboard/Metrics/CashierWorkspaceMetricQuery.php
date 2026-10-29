<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\CashMovement;
use App\Models\OperationDraft;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\CashRegister;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

final class CashierWorkspaceMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $branchId = $context->branch?->id;
        $canViewCash = $context->hasModule('cash_register') && $context->hasPermission(Permissions::CASH_REGISTER_VIEW);
        $session = $canViewCash && $branchId
            ? CashRegister::currentOpenSession((int) $context->business->id, branchId: $branchId)
            : null;
        $salesPeriod = $session ? 'current_session' : 'today';
        $activePeriod = $session
            ? DashboardPeriod::range(DashboardPeriod::CURRENT, $session->opened_at, now())
            : $context->period(DashboardPeriod::TODAY);
        $sales = $this->salesQuery($context, $activePeriod);
        $summary = (clone $sales)->selectRaw('COUNT(*) as count, COALESCE(SUM(sales.total), 0) as total')->first();
        $salesCount = (int) $summary->count;
        $salesTotal = (float) $summary->total;
        $pending = $this->pendingDrafts($context, $branchId);

        return [
            'cash_session' => $canViewCash ? $this->cashSession($session, $context) : null,
            'sales_period' => $salesPeriod,
            'sales_today' => ['count' => $salesCount, 'total' => $this->money($salesTotal), 'average_ticket' => $this->money($salesCount ? $salesTotal / $salesCount : 0)],
            'collections_today' => $this->collections($context, $activePeriod, $branchId),
            'payment_mix' => $this->paymentMix($context, $activePeriod, $branchId),
            'pending' => $pending,
            'recent_activity' => $this->recentActivity($context, $activePeriod, $branchId, $sales),
            'attention' => $this->attention($session, $canViewCash, $pending),
        ];
    }

    private function cashSession(?object $session, DashboardContext $context): array
    {
        if (! $session) return ['status' => 'closed'];

        return ['status' => 'open', 'id' => $session->id, 'opened_at' => $session->opened_at?->timezone($context->timezone)->toIso8601String(), 'opened_by' => $session->opened_by, 'expected_cash' => $this->money((float) CashRegister::summary($session)['expected_cash'])];
    }

    private function salesQuery(DashboardContext $context, DashboardPeriod $period): Builder
    {
        return Sale::query()
            ->where('sales.business_id', $context->business->id)
            ->when($context->branch?->id, fn (Builder $query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where('sales.created_by', $context->user->id)
            ->where(fn (Builder $query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$period->start, $period->end]);
    }

    private function collections(DashboardContext $context, DashboardPeriod $period, ?int $branchId): array
    {
        $summary = $this->paymentsQuery($context, $period, $branchId)->selectRaw('COUNT(*) as count, COALESCE(SUM(sale_payments.amount), 0) as total')->first();
        return ['count' => (int) $summary->count, 'total' => $this->money((float) $summary->total)];
    }

    private function paymentMix(DashboardContext $context, DashboardPeriod $period, ?int $branchId): array
    {
        $rows = $this->paymentsQuery($context, $period, $branchId)
            ->selectRaw('sale_payments.method, COUNT(*) as count, COALESCE(SUM(sale_payments.amount), 0) as total')
            ->groupBy('sale_payments.method')->get()
            ->groupBy(fn (object $row) => $this->paymentMethodKey((string) $row->method))
            ->map(fn ($payments, string $key) => ['method' => $key, 'label' => $this->paymentMethodLabel($key), 'count' => (int) $payments->sum('count'), 'total' => round((float) $payments->sum('total'), 2)])
            ->sortByDesc('total')->values();
        $total = (float) $rows->sum('total');
        return $rows->map(fn (array $row) => [...$row, 'percentage' => $total > 0 ? round(($row['total'] / $total) * 100, 2) : 0])->all();
    }

    private function paymentsQuery(DashboardContext $context, DashboardPeriod $period, ?int $branchId): Builder
    {
        return SalePayment::query()
            ->join('sales', function ($join) { $join->on('sales.id', '=', 'sale_payments.sale_id')->whereColumn('sales.business_id', 'sale_payments.business_id'); })
            ->where('sale_payments.business_id', $context->business->id)
            ->where('sale_payments.collected_by', $context->user->id)
            ->when($branchId, fn (Builder $query, int $id) => $query->where('sales.branch_id', $id))
            ->where('sale_payments.status', 'captured')
            ->where(fn (Builder $query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sale_payments.collected_at', [$period->start, $period->end]);
    }

    private function pendingDrafts(DashboardContext $context, ?int $branchId): array
    {
        $count = OperationDraft::query()->where('business_id', $context->business->id)
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->where('user_id', $context->user->id)->where('type', OperationDraft::TYPE_POS_SALE)->where('status', OperationDraft::STATUS_ACTIVE)->count();
        return ['active_pos_drafts_count' => $count];
    }

    private function recentActivity(DashboardContext $context, DashboardPeriod $period, ?int $branchId, Builder $sales): array
    {
        $activity = collect();
        $canOpenSales = $context->hasPermission(Permissions::SALES_VIEW);
        $activity = $activity->concat((clone $sales)->latest('sales.created_at')->limit(5)->get(['id', 'business_id', 'business_number', 'customer_name', 'total', 'created_at'])->map(fn (Sale $sale) => ['key' => "sale:{$sale->id}", 'type' => 'sale', 'title' => 'Venta #'.($sale->business_number ?? $sale->id), 'detail' => $sale->customer_name ?: 'Consumidor Final', 'amount' => (float) $sale->total, 'created_at' => $sale->created_at?->timezone($context->timezone)->toIso8601String(), 'href' => $canOpenSales ? route('sales.show', $sale, absolute: false) : null]));
        $activity = $activity->concat($this->paymentsQuery($context, $period, $branchId)->latest('sale_payments.collected_at')->limit(5)->get(['sale_payments.id', 'sale_payments.method', 'sale_payments.amount', 'sale_payments.collected_at'])->map(fn (SalePayment $payment) => ['key' => "payment:{$payment->id}", 'type' => 'payment', 'title' => 'Cobro '.$this->paymentMethodLabel($this->paymentMethodKey((string) $payment->method)), 'detail' => 'Pago capturado', 'amount' => (float) $payment->amount, 'created_at' => $payment->collected_at?->timezone($context->timezone)->toIso8601String(), 'href' => null]));
        if ($branchId && $context->hasModule('cash_register') && $context->hasPermission(Permissions::CASH_REGISTER_VIEW)) $activity = $activity->concat(CashMovement::query()->where('business_id', $context->business->id)->where('branch_id', $branchId)->where('created_by', $context->user->id)->whereBetween('created_at', [$period->start, $period->end])->latest('created_at')->limit(5)->get(['id', 'type', 'amount', 'description', 'created_at'])->map(fn (CashMovement $movement) => ['key' => "cash:{$movement->id}", 'type' => 'cash', 'title' => $this->cashMovementLabel($movement->type), 'detail' => $movement->description ?: 'Movimiento de caja', 'amount' => (float) $movement->amount, 'created_at' => $movement->created_at?->timezone($context->timezone)->toIso8601String(), 'href' => route('cash-register.index', absolute: false)]));
        return $activity->sortByDesc('created_at')->take(8)->values()->all();
    }

    private function attention(?object $session, bool $canViewCash, array $pending): array
    {
        $items = [];
        if ($canViewCash && ! $session) $items[] = ['key' => 'cash.closed', 'severity' => 'info', 'title' => 'No hay una caja abierta', 'detail' => 'Abre o revisa la caja antes de continuar.', 'href' => route('cash-register.index', absolute: false)];
        if ($pending['active_pos_drafts_count'] > 0) $items[] = ['key' => 'drafts.active', 'severity' => 'warning', 'title' => "{$pending['active_pos_drafts_count']} venta(s) pausada(s)", 'detail' => 'Puedes continuarlas desde el POS.', 'href' => route('sales.create', absolute: false)];
        return $items;
    }

    private function paymentMethodKey(string $method): string { return match ($method) { 'cash' => 'cash', 'card' => 'card', 'bank_transfer', 'transfer' => 'transfer', 'check' => 'check', default => 'other' }; }
    private function paymentMethodLabel(string $method): string { return match ($method) { 'cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'check' => 'Cheque', default => 'Otro' }; }
    private function cashMovementLabel(string $type): string { return match ($type) { 'opening' => 'Apertura de caja', 'sale_cash' => 'Venta en efectivo', 'credit_payment_cash' => 'Cobro de crédito', 'expense' => 'Gasto de caja', default => 'Movimiento de caja' }; }
    private function money(float $amount): string { return number_format($amount, 2, '.', ''); }
}
