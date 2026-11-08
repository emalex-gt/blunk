<?php

namespace App\Support;

use App\Models\CashRegisterSession;
use App\Models\Sale;
use Carbon\CarbonInterface;

/**
 * Read-only commercial sales summary for a cash-register session.
 *
 * Sales do not persist a session id. A captured payment linked to the session
 * is authoritative; older or credit sales are included only when their
 * creation time belongs to exactly one session in the same branch. A historic
 * closing uses the commercial state as of its closing timestamp.
 */
final class CashRegisterClosingSales
{
    /** @return array{total: float, gross_total: float, same_period_cancellations: float, credit_sales_total: float, count: int, direct_count: int, reconstructed_count: int, ambiguous_count: int} */
    public static function forSession(CashRegisterSession $session): array
    {
        $from = $session->opened_at;
        $to = $session->closed_at ?? now();
        $sessions = CashRegisterSession::query()
            ->where('business_id', $session->business_id)
            ->where('branch_id', $session->branch_id)
            ->where('opened_at', '<=', $to)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $from))
            ->get(['id', 'opened_at', 'closed_at']);

        $sales = Sale::query()
            ->where('business_id', $session->business_id)
            ->where('branch_id', $session->branch_id)
            ->where('created_at', '<=', $to)
            ->where(function ($query) use ($session, $from, $to) {
                $query->whereHas('capturedPayments', fn ($payments) => $payments
                    ->where('cash_register_session_id', $session->id))
                    ->orWhereBetween('created_at', [$from, $to]);
            })
            ->with(['capturedPayments:id,sale_id,cash_register_session_id'])
            ->get(['id', 'business_id', 'branch_id', 'total', 'is_credit_sale', 'status', 'created_at', 'cancelled_at']);

        $included = collect();
        $directCount = 0;
        $reconstructedCount = 0;
        $ambiguousCount = 0;

        foreach ($sales as $sale) {
            $sessionIds = $sale->capturedPayments
                ->pluck('cash_register_session_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique();

            // A sale split between sessions cannot be attributed safely by its
            // payments. Do not turn that inconsistency into two sales totals.
            if ($sessionIds->contains((int) $session->id) && $sessionIds->count() === 1) {
                $included->push($sale);
                $directCount++;
                continue;
            }

            // A persisted link to another session is stronger evidence than a
            // coincident timestamp and must never be reconstructed here. This
            // also covers the inconsistent multi-session payment above.
            if ($sessionIds->isNotEmpty()) {
                if ($sessionIds->contains((int) $session->id)) {
                    $ambiguousCount++;
                }

                continue;
            }

            $matches = $sessions->filter(fn (CashRegisterSession $candidate) => self::inSession($sale->created_at, $candidate));
            if ($matches->count() === 1 && (int) $matches->first()->id === (int) $session->id) {
                $included->push($sale);
                $reconstructedCount++;
            } elseif ($matches->contains(fn (CashRegisterSession $candidate) => (int) $candidate->id === (int) $session->id)) {
                $ambiguousCount++;
            }
        }

        $gross = round((float) $included->sum(fn (Sale $sale) => (float) $sale->total), 2);
        $samePeriodCancellations = round((float) $included
            ->filter(fn (Sale $sale) => $sale->status === 'cancelled' && $sale->cancelled_at?->lessThanOrEqualTo($to))
            ->sum(fn (Sale $sale) => (float) $sale->total), 2);
        $net = round($gross - $samePeriodCancellations, 2);

        return [
            'total' => $net,
            'gross_total' => $gross,
            'same_period_cancellations' => $samePeriodCancellations,
            'credit_sales_total' => round((float) $included
                ->filter(fn (Sale $sale) => (bool) $sale->is_credit_sale && ! ($sale->status === 'cancelled' && $sale->cancelled_at?->lessThanOrEqualTo($to)))
                ->sum(fn (Sale $sale) => (float) $sale->total), 2),
            'count' => $included->count(),
            'direct_count' => $directCount,
            'reconstructed_count' => $reconstructedCount,
            'ambiguous_count' => $ambiguousCount,
        ];
    }

    private static function inSession(?CarbonInterface $at, CashRegisterSession $session): bool
    {
        if (! $at || ! $session->opened_at) {
            return false;
        }

        $end = $session->closed_at ?? now();

        return $at->greaterThanOrEqualTo($session->opened_at) && $at->lessThanOrEqualTo($end);
    }
}
