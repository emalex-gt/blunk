<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\CustomerCreditAccount;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;

final class AccountsReceivableMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = CustomerCreditAccount::query()
            ->where('business_id', $context->business->id)
            ->where('current_balance', '>', 0)
            ->selectRaw('COUNT(*) as account_count, COALESCE(SUM(current_balance), 0) as amount')
            ->first();

        return [
            'count' => (int) ($summary->account_count ?? 0),
            'amount' => number_format((float) ($summary->amount ?? 0), 2, '.', ''),
        ];
    }
}
