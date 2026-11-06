<?php

namespace App\Services\System;

use App\Models\OperationalIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OperationalIncidentManager
{
    public function __construct(private readonly OperationalIncidentNotifier $notifier) {}

    public function observe(string $fingerprint, string $type, string $severity, string $message, array $metadata = [], ?int $businessId = null): OperationalIncident
    {
        $notify = false;
        $now = now();
        $incident = DB::transaction(function () use ($fingerprint, $type, $severity, $message, $metadata, $businessId, $now, &$notify) {
            $incident = OperationalIncident::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            if (! $incident) {
                $notify = true;
                return OperationalIncident::query()->create(compact('fingerprint', 'type', 'severity', 'message') + [
                    'business_id' => $businessId, 'metadata' => $metadata, 'status' => OperationalIncident::OPEN,
                    'detected_at' => $now, 'last_occurred_at' => $now, 'last_notified_at' => $now, 'last_reminded_at' => $now,
                ]);
            }
            $reopened = $incident->status === OperationalIncident::RESOLVED;
            $remind = ! $incident->last_reminded_at || $incident->last_reminded_at->lte($now->copy()->subMinutes((int) config('operational_monitor.reminder_minutes')));
            $notify = $reopened || ($incident->status === OperationalIncident::OPEN && $remind);
            $incident->fill(['type' => $type, 'severity' => $severity, 'message' => $message, 'metadata' => $metadata, 'business_id' => $businessId, 'last_occurred_at' => $now]);
            $incident->occurrence_count++;
            if ($reopened) {
                $incident->status = OperationalIncident::OPEN;
                $incident->detected_at = $now;
                $incident->resolved_at = null;
                $incident->resolved_by = null;
            }
            if ($notify) { $incident->last_reminded_at = $now; $incident->last_notified_at ??= $now; }
            $incident->save();
            return $incident;
        });
        if ($notify) { $this->notifier->notify($incident); }
        return $incident;
    }

    public function recoverAbsent(array $activeFingerprints): void
    {
        OperationalIncident::query()->whereIn('status', [OperationalIncident::OPEN, OperationalIncident::ACKNOWLEDGED])
            ->where('fingerprint', 'like', 'monitor:%')->whereNotIn('fingerprint', $activeFingerprints ?: ['__none__'])
            ->orderBy('id')->each(function (OperationalIncident $incident): void {
                $incident->update(['status' => OperationalIncident::RESOLVED, 'resolved_at' => now()]);
                $this->notifier->notify($incident->fresh(), true);
            });
    }

    public function acknowledge(OperationalIncident $incident, User $user): void
    {
        if ($incident->status === OperationalIncident::RESOLVED) return;
        $incident->update(['status' => OperationalIncident::ACKNOWLEDGED, 'acknowledged_by' => $user->id, 'acknowledged_at' => now()]);
    }
}
