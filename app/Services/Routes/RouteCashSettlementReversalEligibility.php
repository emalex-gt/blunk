<?php

namespace App\Services\Routes;

use App\Models\CashMovement;
use App\Models\RouteCashSettlement;
use App\Support\CashRegister;

/**
 * Read-only facts for a future reversal UI. The mutation service repeats all
 * relevant checks under locks before writing, so this class never authorizes
 * a reversal by itself.
 */
class RouteCashSettlementReversalEligibility
{
    /** @return array{eligible: bool, reason_code: ?string, correction_mode: ?string, requires_cash_adjustment_confirmation: bool} */
    public function for(RouteCashSettlement $settlement): array
    {
        if ($settlement->status === 'reversed' || $settlement->reversal()->exists()) {
            return $this->ineligible('already_reversed');
        }
        if ($settlement->status !== 'confirmed') {
            return $this->ineligible('settlement_not_confirmed');
        }
        if (round((float) $settlement->difference_amount, 2) !== 0.0
            || round((float) $settlement->expected_amount, 2) !== round((float) $settlement->received_amount, 2)) {
            return $this->ineligible('settlement_not_exact');
        }
        if ($settlement->variance()->exists()) {
            return $this->ineligible('settlement_has_variance');
        }

        $movement = $settlement->cashMovement;
        $session = $settlement->cashSession;
        if (! $movement instanceof CashMovement || ! $session
            || (int) $movement->business_id !== (int) $settlement->business_id
            || (int) $movement->branch_id !== (int) $settlement->branch_id
            || (int) $movement->cash_register_session_id !== (int) $session->id
            || $movement->type !== 'route_cash_settlement'
            || $movement->reference_type !== 'route_cash_settlement'
            || (int) $movement->reference_id !== (int) $settlement->id
            || round((float) $movement->amount, 2) !== round((float) $settlement->received_amount, 2)
            || (int) $session->business_id !== (int) $settlement->business_id
            || (int) $session->branch_id !== (int) $settlement->branch_id) {
            return $this->ineligible('original_cash_movement_invalid');
        }

        if ($session->status === 'closed') {
            return $this->eligible('ledger_only', false);
        }
        if ($session->status !== 'open') {
            return $this->ineligible('original_session_invalid');
        }

        $current = CashRegister::currentOpenSession((int) $settlement->business_id, false, (int) $settlement->branch_id);
        if (! $current || (int) $current->id !== (int) $session->id) {
            return $this->ineligible('open_session_not_current', 'blocked_open_non_current');
        }

        return $this->eligible('current_session_adjustment', true);
    }

    /** @return array{eligible: bool, reason_code: null, correction_mode: string, requires_cash_adjustment_confirmation: bool} */
    private function eligible(string $mode, bool $requiresConfirmation): array
    {
        return [
            'eligible' => true,
            'reason_code' => null,
            'correction_mode' => $mode,
            'requires_cash_adjustment_confirmation' => $requiresConfirmation,
        ];
    }

    /** @return array{eligible: bool, reason_code: string, correction_mode: ?string, requires_cash_adjustment_confirmation: bool} */
    private function ineligible(string $reason, ?string $mode = null): array
    {
        return [
            'eligible' => false,
            'reason_code' => $reason,
            'correction_mode' => $mode,
            'requires_cash_adjustment_confirmation' => false,
        ];
    }
}
