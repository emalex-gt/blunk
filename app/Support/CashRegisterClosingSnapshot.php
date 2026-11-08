<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterClosingCorrection;
use App\Models\CashRegisterClosingSnapshot as ClosingSnapshot;
use App\Models\CashRegisterSession;
use App\Models\TenantSetting;

/** Single, versioned contract for live, historical and printed cash closings. */
final class CashRegisterClosingSnapshot
{
    public const SCHEMA_VERSION = 1;

    public static function capture(CashRegisterSession $session): ClosingSnapshot
    {
        $existing = ClosingSnapshot::query()
            ->where('cash_register_session_id', $session->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return ClosingSnapshot::query()->create([
            'business_id' => $session->business_id,
            'branch_id' => $session->branch_id,
            'cash_register_session_id' => $session->id,
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot' => self::build($session, false),
            'captured_at' => now(),
        ]);
    }

    public static function forSession(CashRegisterSession $session): array
    {
        $snapshot = $session->relationLoaded('closingSnapshot')
            ? $session->closingSnapshot
            : $session->closingSnapshot()->first();

        if ($snapshot) {
            return [...$snapshot->snapshot, 'source' => 'snapshot', 'schema_version' => $snapshot->schema_version];
        }

        return [...self::build($session, true), 'source' => 'reconstructed', 'schema_version' => null];
    }

    private static function build(CashRegisterSession $session, bool $reconstructed): array
    {
        $business = Business::query()->find($session->business_id);
        $branch = Branch::query()->where('business_id', $session->business_id)->find($session->branch_id);
        $settings = TenantSetting::query()->where('business_id', $session->business_id)->first();
        $session->loadMissing(['openedBy:id,name', 'closedBy:id,name']);

        return [
            'session' => [
                'id' => $session->id,
                'business_id' => $session->business_id,
                'branch_id' => $session->branch_id,
                'status' => $session->status,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'closed_at' => $session->closed_at?->toIso8601String(),
                'opened_by' => $session->openedBy?->name,
                'closed_by' => $session->closedBy?->name,
                'opening_amount' => (float) $session->opening_amount,
                'expected_cash' => (float) $session->expected_cash,
                'counted_cash' => $session->counted_cash === null ? null : (float) $session->counted_cash,
                'difference' => $session->difference === null ? null : (float) $session->difference,
                'notes' => $session->notes,
                'closing_notes' => $session->closing_notes,
            ],
            'header' => [
                'business_logo_url' => $settings?->company_logo_url,
                'business_name' => $settings?->company_name ?: $business?->name,
                'business_tax_id' => $settings?->company_tax_id,
                'business_address' => $settings?->company_address,
                'business_phone' => $settings?->company_phone,
                'branch_name' => $branch?->name,
                'branch_address' => $branch?->address,
            ],
            'commercial' => CashRegisterClosingSales::forSession($session),
            'cash_summary' => CashRegister::summary($session),
            'payment_operations' => CashRegisterClosingOperations::forSession($session),
            'movements' => CashRegister::movements($session)->map(fn ($movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'amount' => (float) $movement->amount,
                'description' => $movement->description,
                'created_at' => $movement->created_at?->toIso8601String(),
                'created_by_name' => $movement->createdBy?->name,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
            ])->all(),
            'corrections' => CashRegisterClosingCorrection::query()
                ->where('business_id', $session->business_id)
                ->where('branch_id', $session->branch_id)
                ->where('correction_cash_register_session_id', $session->id)
                ->with(['sale:id,business_number', 'originalSession:id,opened_at,closed_at', 'responsibleUser:id,name'])
                ->orderBy('occurred_at')
                ->get()
                ->map(fn (CashRegisterClosingCorrection $correction) => [
                    'id' => $correction->id,
                    'type' => $correction->correction_type,
                    'amount' => (float) $correction->amount,
                    'payment_method' => $correction->payment_method,
                    'occurred_at' => $correction->occurred_at?->toIso8601String(),
                    'reason' => $correction->reason,
                    'sale_number' => $correction->sale?->business_number,
                    'original_session_id' => $correction->original_cash_register_session_id,
                    'responsible_user' => $correction->responsibleUser?->name,
                ])->all(),
            'reconstructed' => $reconstructed,
        ];
    }
}
