<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\OperationalIncident;
use App\Models\OperationalMonitorRun;
use App\Services\System\OperationalIncidentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationalMonitorController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,acknowledged,resolved'],
            'severity' => ['nullable', 'in:P0,P1,P2'],
            'type' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'in:test,production,unknown'],
        ]);
        $query = OperationalIncident::query()->with(['business:id,name', 'acknowledgedBy:id,name', 'resolvedBy:id,name'])->latest('last_occurred_at');
        foreach (['status', 'severity', 'type'] as $field) if (filled($filters[$field] ?? null)) $query->where($field, $filters[$field]);
        if (filled($filters['environment'] ?? null)) {
            $query->whereIn('type', ['fel_stale_intents', 'fel_critical_incidents'])
                ->where(function ($query) use ($filters): void {
                    $query->where('metadata->environment', $filters['environment']);
                    if ($filters['environment'] === 'unknown') {
                        $query->orWhereNull('metadata->environment');
                    }
                });
        }

        $lastRun = OperationalMonitorRun::query()->latest('finished_at')->first([
            'status', 'checks', 'active_incident_count', 'started_at', 'finished_at', 'duration_ms',
        ]);

        return Inertia::render('SuperAdmin/System/Monitor', [
            'filters' => [
                'status' => $filters['status'] ?? '',
                'severity' => $filters['severity'] ?? '',
                'type' => $filters['type'] ?? '',
                'environment' => $filters['environment'] ?? '',
            ],
            'incidents' => $query->paginate(50)->withQueryString(),
            'summary' => [
                'p0_open' => OperationalIncident::query()->whereIn('status', ['open', 'acknowledged'])->where('severity', 'P0')->count(),
                'p1_open' => OperationalIncident::query()->whereIn('status', ['open', 'acknowledged'])->where('severity', 'P1')->count(),
                'active' => OperationalIncident::query()->whereIn('status', ['open', 'acknowledged'])->count(),
                'historical' => OperationalIncident::query()->where('status', OperationalIncident::RESOLVED)->count(),
            ],
            'lastRun' => $lastRun,
            'health' => $this->health($lastRun),
        ]);
    }

    public function acknowledge(Request $request, OperationalIncident $incident, OperationalIncidentManager $manager): RedirectResponse
    {
        $manager->acknowledge($incident, $request->user());
        return back();
    }

    private function health(?OperationalMonitorRun $lastRun): array
    {
        $enabled = (bool) config('operational_monitor.enabled');
        $intervalMinutes = (int) config('operational_monitor.schedule_interval_minutes');
        $overdueMinutes = (int) config('operational_monitor.run_overdue_minutes');

        if (! $enabled) {
            return [
                'state' => 'disabled',
                'enabled' => false,
                'message' => 'El Monitor está desactivado por configuración.',
                'expected_next_at' => null,
                'overdue_at' => null,
            ];
        }

        if (! $lastRun || ! $lastRun->finished_at) {
            return [
                'state' => 'no_runs',
                'enabled' => true,
                'message' => 'No hay ejecuciones registradas todavía.',
                'expected_next_at' => null,
                'overdue_at' => null,
            ];
        }

        $expectedNextAt = $lastRun->finished_at->copy()->addMinutes($intervalMinutes);
        $overdueAt = $lastRun->finished_at->copy()->addMinutes($overdueMinutes);
        $checksFailed = $lastRun->status === 'failed'
            || collect($lastRun->checks)->contains(fn (array $check) => ($check['status'] ?? null) === 'failed');
        $state = now()->gt($overdueAt)
            ? 'overdue'
            : ($checksFailed ? 'checks_failed' : ($lastRun->status === 'degraded' ? 'incidents' : 'healthy'));

        return [
            'state' => $state,
            'enabled' => true,
            'message' => match ($state) {
                'overdue' => 'La última ejecución superó el umbral configurado; el scheduler podría estar detenido o retrasado.',
                'checks_failed' => 'La última ejecución terminó, pero una o más comprobaciones fallaron.',
                'incidents' => 'La última ejecución terminó con incidencias activas.',
                default => 'La última ejecución terminó correctamente. Esto no confirma por sí solo que el scheduler siga activo.',
            },
            'expected_next_at' => $expectedNextAt->utc()->toIso8601String(),
            'overdue_at' => $overdueAt->utc()->toIso8601String(),
        ];
    }
}
