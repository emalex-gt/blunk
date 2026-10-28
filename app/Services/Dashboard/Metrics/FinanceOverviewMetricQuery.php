<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\CustomerCreditPayment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FinanceOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $salesAllowed = $context->hasModule('reports') && $context->hasPermission(Permissions::REPORTS_SALES_VIEW);
        $profitAllowed = $context->hasModule('reports') && $context->hasPermission(Permissions::REPORTS_PROFIT_VIEW);
        $purchasesAllowed = $context->hasModule('purchases') && $context->hasPermission(Permissions::PURCHASES_VIEW);
        $inventoryAllowed = $context->hasModule('inventory') && $context->hasPermission(Permissions::REPORTS_WAREHOUSE_MONEY_VIEW);
        $receivablesAllowed = $context->hasModule('credits') && $context->hasPermission(Permissions::CREDITS_ACCOUNTS_VIEW) && $context->hasFeature('credit_sales');
        $collectionsAllowed = $context->hasModule('credits') && $context->hasPermission(Permissions::CREDITS_PAYMENTS_VIEW) && $context->hasFeature('credit_sales');
        $month = $context->period(DashboardPeriod::THIS_MONTH);
        $previousMonth = $this->previousComparableMonth($context);
        $today = now()->timezone($context->timezone)->startOfDay();

        $salesMonth = $salesAllowed ? $this->salesSummary($context, $month, $previousMonth) : null;
        $profitMonth = $profitAllowed ? $this->profitSummary($context, $month, $previousMonth) : null;
        $purchasesMonth = $purchasesAllowed ? $this->purchaseSummary($context, $month, $previousMonth) : null;
        $receivables = $receivablesAllowed ? $this->receivables($context, $today) : null;

        return [
            'sales_month' => $salesMonth,
            'profit_month' => $profitMonth,
            'purchases_month' => $purchasesMonth,
            'inventory_value' => $inventoryAllowed ? $this->inventoryValue($context) : null,
            'receivables' => $receivables,
            'aging' => $receivablesAllowed ? $this->aging($context, $today) : null,
            'top_debtors' => $receivablesAllowed ? $this->topDebtors($context, $today) : [],
            'overdue_invoices' => $receivablesAllowed ? $this->overdueInvoices($context, $today) : [],
            'collections_month' => $collectionsAllowed ? $this->collectionSummary($context, $month, $previousMonth) : null,
            'collections_mix' => $collectionsAllowed ? $this->collectionMix($context, $month) : [],
            'recent_collections' => $collectionsAllowed ? $this->recentCollections($context) : [],
            'financial_trend' => $this->financialTrend($context, $salesAllowed, $profitAllowed, $purchasesAllowed),
        ];
    }

    private function salesSummary(DashboardContext $context, DashboardPeriod $month, DashboardPeriod $previous): array
    {
        $current = $this->salesQuery($context, $month)->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')->first();
        $prior = $this->salesQuery($context, $previous)->sum('total');
        $total = (float) $current->total;
        $count = (int) $current->count;

        return [
            'total' => $this->money($total),
            'count' => $count,
            'average_ticket' => $this->money($count ? $total / $count : 0),
            'previous_total' => $this->money((float) $prior),
            'change_percent' => $this->change($total, (float) $prior),
        ];
    }

    private function profitSummary(DashboardContext $context, DashboardPeriod $month, DashboardPeriod $previous): array
    {
        $current = $this->profitQuery($context, $month)->selectRaw('COALESCE(SUM(sale_items.total_cost), 0) as cost_total, COALESCE(SUM(sale_items.profit_amount), 0) as profit_total')->first();
        $priorProfit = (float) $this->profitQuery($context, $previous)->sum('sale_items.profit_amount');
        $sales = (float) $this->salesQuery($context, $month)->sum('total');
        $profit = (float) $current->profit_total;

        return [
            'sales_total' => $this->money($sales),
            'cost_total' => $this->money((float) $current->cost_total),
            'profit_total' => $this->money($profit),
            'margin_percent' => $sales > 0 ? round(($profit / $sales) * 100, 2) : null,
            'previous_profit_total' => $this->money($priorProfit),
            'change_percent' => $this->change($profit, $priorProfit),
        ];
    }

    private function purchaseSummary(DashboardContext $context, DashboardPeriod $month, DashboardPeriod $previous): array
    {
        $current = $this->purchasesQuery($context, $month)->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')->first();
        $prior = (float) $this->purchasesQuery($context, $previous)->sum('total');
        $total = (float) $current->total;
        $count = (int) $current->count;

        return [
            'total' => $this->money($total),
            'count' => $count,
            'average' => $this->money($count ? $total / $count : 0),
            'previous_total' => $this->money($prior),
            'change_percent' => $this->change($total, $prior),
        ];
    }

    private function inventoryValue(DashboardContext $context): array
    {
        $query = Product::query()->where('products.business_id', $context->business->id)->where('products.is_active', true);
        $stock = 'products.stock';

        if ($branchId = $context->branchIdForScopedMetric()) {
            $query->leftJoin('product_branch_stocks as pbs', function ($join) use ($context, $branchId) {
                $join->on('pbs.product_id', '=', 'products.id')
                    ->where('pbs.business_id', '=', $context->business->id)
                    ->where('pbs.branch_id', '=', $branchId);
            });
            $stock = 'COALESCE(pbs.stock, 0)';
        }

        $summary = $query->selectRaw("COUNT(*) as sku_count, COALESCE(SUM({$stock} * products.cost_price), 0) as amount")->first();

        return ['amount' => $this->money((float) $summary->amount), 'sku_count' => (int) $summary->sku_count];
    }

    private function receivables(DashboardContext $context, Carbon $today): array
    {
        $base = $this->openCreditSales($context);
        $summary = (clone $base)->selectRaw('COALESCE(SUM(credit_balance), 0) as total, COUNT(*) as invoice_count, COUNT(DISTINCT customer_id) as customer_count')->first();
        $overdue = (clone $base)->whereDate('due_date', '<', $today->toDateString())->selectRaw('COALESCE(SUM(credit_balance), 0) as total, COUNT(*) as count')->first();
        $dueSoon = (clone $base)->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->sum('credit_balance');

        return [
            'total' => $this->money((float) $summary->total),
            'invoice_count' => (int) $summary->invoice_count,
            'customer_count' => (int) $summary->customer_count,
            'overdue_total' => $this->money((float) $overdue->total),
            'overdue_count' => (int) $overdue->count,
            'due_next_7_days' => $this->money((float) $dueSoon),
        ];
    }

    private function aging(DashboardContext $context, Carbon $today): array
    {
        $todayDate = $today->toDateString();
        $yesterday = $today->copy()->subDay()->toDateString();
        $buckets = [
            'current' => (clone $this->openCreditSales($context))->whereDate('due_date', '>=', $todayDate),
            'overdue_1_30' => (clone $this->openCreditSales($context))->whereBetween('due_date', [$today->copy()->subDays(30)->toDateString(), $yesterday]),
            'overdue_31_60' => (clone $this->openCreditSales($context))->whereBetween('due_date', [$today->copy()->subDays(60)->toDateString(), $today->copy()->subDays(31)->toDateString()]),
            'overdue_61_90' => (clone $this->openCreditSales($context))->whereBetween('due_date', [$today->copy()->subDays(90)->toDateString(), $today->copy()->subDays(61)->toDateString()]),
            'overdue_90_plus' => (clone $this->openCreditSales($context))->whereDate('due_date', '<', $today->copy()->subDays(90)->toDateString()),
            'no_due_date' => (clone $this->openCreditSales($context))->whereNull('due_date'),
        ];

        return collect($buckets)->map(function (Builder $query) {
            $summary = $query->selectRaw('COALESCE(SUM(credit_balance), 0) as amount, COUNT(*) as count')->first();

            return ['amount' => $this->money((float) $summary->amount), 'count' => (int) $summary->count];
        })->all();
    }

    private function topDebtors(DashboardContext $context, Carbon $today): array
    {
        return $this->openCreditSales($context)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, MAX(customer_name) as name, COALESCE(SUM(credit_balance), 0) as total_balance, COALESCE(SUM(CASE WHEN due_date < ? THEN credit_balance ELSE 0 END), 0) as overdue_balance, MIN(due_date) as oldest_due_date', [$today->toDateString()])
            ->groupBy('customer_id')
            ->orderByDesc('total_balance')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'customer_id' => (int) $row->customer_id,
                'name' => $row->name,
                'total_balance' => $this->money((float) $row->total_balance),
                'overdue_balance' => $this->money((float) $row->overdue_balance),
                'oldest_due_date' => $row->oldest_due_date,
                'href' => $context->hasPermission(Permissions::CREDITS_STATEMENT_VIEW) ? route('credits.accounts.statement', $row->customer_id, absolute: false) : null,
            ])
            ->all();
    }

    private function overdueInvoices(DashboardContext $context, Carbon $today): array
    {
        return $this->openCreditSales($context)
            ->whereDate('due_date', '<', $today->toDateString())
            ->orderBy('due_date')
            ->orderByDesc('credit_balance')
            ->limit(5)
            ->get(['id', 'business_number', 'customer_id', 'customer_name', 'due_date', 'credit_balance'])
            ->map(fn (Sale $sale) => [
                'sale_id' => $sale->id,
                'business_number' => $sale->business_number,
                'customer_id' => $sale->customer_id,
                'customer_name' => $sale->customer_name,
                'due_date' => $sale->due_date?->toDateString(),
                'days_overdue' => $sale->due_date?->diffInDays($today) ?? 0,
                'balance' => $this->money((float) $sale->credit_balance),
                'href' => $sale->customer_id && $context->hasPermission(Permissions::CREDITS_STATEMENT_VIEW) ? route('credits.accounts.statement', $sale->customer_id, absolute: false) : null,
            ])
            ->all();
    }

    private function collectionSummary(DashboardContext $context, DashboardPeriod $month, DashboardPeriod $previous): array
    {
        $current = $this->collectionsQuery($context, $month)->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')->first();
        $prior = (float) $this->collectionsQuery($context, $previous)->sum('amount');
        $total = (float) $current->total;
        $count = (int) $current->count;

        return [
            'total' => $this->money($total),
            'count' => $count,
            'average' => $this->money($count ? $total / $count : 0),
            'previous_total' => $this->money($prior),
            'change_percent' => $this->change($total, $prior),
        ];
    }

    private function collectionMix(DashboardContext $context, DashboardPeriod $month): array
    {
        $rows = $this->collectionsQuery($context, $month)
            ->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('payment_method')
            ->get()
            ->groupBy(fn ($row) => $this->paymentMethodKey((string) $row->payment_method))
            ->map(fn ($payments, string $key) => ['method' => $key, 'label' => $this->paymentMethodLabel($key), 'total' => round((float) $payments->sum('total'), 2), 'count' => (int) $payments->sum('count')])
            ->sortByDesc('total')
            ->values();
        $total = (float) $rows->sum('total');

        return $rows->map(fn (array $row) => [...$row, 'percentage' => $total > 0 ? round(($row['total'] / $total) * 100, 2) : 0])->all();
    }

    private function recentCollections(DashboardContext $context): array
    {
        return CustomerCreditPayment::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where('status', 'completed')
            ->with('customer:id,name')
            ->latest()
            ->limit(5)
            ->get(['id', 'business_id', 'customer_id', 'payment_number', 'amount', 'payment_method', 'created_at'])
            ->map(fn (CustomerCreditPayment $payment) => [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'customer_name' => $payment->customer?->name ?? 'Cliente',
                'amount' => (float) $payment->amount,
                'payment_method' => $this->paymentMethodLabel($this->paymentMethodKey((string) $payment->payment_method)),
                'created_at' => $payment->created_at?->timezone($context->timezone)->format('Y-m-d H:i'),
            ])
            ->all();
    }

    private function financialTrend(DashboardContext $context, bool $salesAllowed, bool $profitAllowed, bool $purchasesAllowed): array
    {
        $current = now()->timezone($context->timezone)->startOfMonth();
        $start = $current->copy()->subMonths(5);
        $period = DashboardPeriod::range(DashboardPeriod::CURRENT, $start, now()->timezone($context->timezone)->endOfDay());
        $sales = $salesAllowed ? $this->monthlySales($context, $period) : collect();
        $profit = $profitAllowed ? $this->monthlyProfit($context, $period) : collect();
        $purchases = $purchasesAllowed ? $this->monthlyPurchases($context, $period) : collect();

        return collect(range(0, 5))->map(function (int $offset) use ($start, $salesAllowed, $profitAllowed, $purchasesAllowed, $sales, $profit, $purchases) {
            $month = $start->copy()->addMonths($offset);
            $key = $month->format('Y-m');

            return [
                'month' => $key,
                'label' => mb_strtolower($month->locale('es')->translatedFormat('M')),
                'sales' => $salesAllowed ? round((float) ($sales->get($key)?->total ?? 0), 2) : null,
                'profit' => $profitAllowed ? round((float) ($profit->get($key)?->total ?? 0), 2) : null,
                'purchases' => $purchasesAllowed ? round((float) ($purchases->get($key)?->total ?? 0), 2) : null,
            ];
        })->all();
    }

    private function monthlySales(DashboardContext $context, DashboardPeriod $period)
    {
        $month = "TO_CHAR((sales.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM')";

        return $this->salesQuery($context, $period)
            ->selectRaw("{$month} as local_month, COALESCE(SUM(sales.total), 0) as total", [$context->timezone])
            ->groupByRaw('1')
            ->get()
            ->keyBy('local_month');
    }

    private function monthlyProfit(DashboardContext $context, DashboardPeriod $period)
    {
        $month = "TO_CHAR((sales.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM')";

        return $this->profitQuery($context, $period)
            ->selectRaw("{$month} as local_month, COALESCE(SUM(sale_items.profit_amount), 0) as total", [$context->timezone])
            ->groupByRaw('1')
            ->get()
            ->keyBy('local_month');
    }

    private function monthlyPurchases(DashboardContext $context, DashboardPeriod $period)
    {
        $month = "TO_CHAR((purchases.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM')";

        return $this->purchasesQuery($context, $period)
            ->selectRaw("{$month} as local_month, COALESCE(SUM(purchases.total), 0) as total", [$context->timezone])
            ->groupByRaw('1')
            ->get()
            ->keyBy('local_month');
    }

    private function salesQuery(DashboardContext $context, DashboardPeriod $period): Builder
    {
        return Sale::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$period->start, $period->end]);
    }

    private function profitQuery(DashboardContext $context, DashboardPeriod $period)
    {
        return DB::table('sale_items')
            ->join('sales', function ($join) {
                $join->on('sales.id', '=', 'sale_items.sale_id')->whereColumn('sales.business_id', 'sale_items.business_id');
            })
            ->where('sales.business_id', $context->business->id)
            ->where('sale_items.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$period->start, $period->end]);
    }

    private function purchasesQuery(DashboardContext $context, DashboardPeriod $period): Builder
    {
        return Purchase::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$period->start, $period->end]);
    }

    private function openCreditSales(DashboardContext $context): Builder
    {
        return Sale::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where('is_credit_sale', true)
            ->where('credit_balance', '>', 0)
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'));
    }

    private function collectionsQuery(DashboardContext $context, DashboardPeriod $period): Builder
    {
        return CustomerCreditPayment::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$period->start, $period->end]);
    }

    private function previousComparableMonth(DashboardContext $context): DashboardPeriod
    {
        $now = now()->timezone($context->timezone);
        $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->addDays(min($now->day, $start->daysInMonth) - 1)->endOfDay();

        return DashboardPeriod::range(DashboardPeriod::CURRENT, $start, $end);
    }

    private function paymentMethodKey(string $method): string
    {
        return match ($method) {
            'cash', 'card', 'check' => $method,
            'bank_transfer', 'transfer' => 'transfer',
            default => 'other',
        };
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'check' => 'Cheque',
            default => 'Otro',
        };
    }

    private function change(float $current, float $previous): ?float
    {
        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 2) : null;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
