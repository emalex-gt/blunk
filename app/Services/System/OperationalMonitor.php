<?php

namespace App\Services\System;

use App\Models\FelIncident;
use App\Models\FiscalDocumentIntent;
use App\Models\OperationalMonitorRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OperationalMonitor
{
    private const FISCAL_SAMPLE_LIMIT = 100;

    private array $checkDetails = [];
    private array $activeFingerprintAliases = [];

    public function __construct(private readonly OperationalIncidentManager $incidents) {}

    public function run(): array
    {
        if (! config('operational_monitor.enabled')) {
            return ['enabled' => false, 'active' => [], 'skipped' => false];
        }

        $lock = Cache::lock('operational-monitor:run', (int) config('operational_monitor.lock_seconds'));
        if (! $lock->get()) {
            return ['enabled' => true, 'active' => [], 'skipped' => true];
        }

        $startedAt = now();
        $startedNs = hrtime(true);
        $active = [];
        $checks = [];
        $this->checkDetails = [];
        $this->activeFingerprintAliases = [];

        try {
            foreach (['database', 'queue', 'fiscal', 'host'] as $check) {
                $checkStartedAt = now();
                $checkStartedNs = hrtime(true);
                try {
                    $detected = $this->{$check}();
                    $active = array_merge($active, $detected);
                    $checks[$check] = $this->checkResult($check, $checkStartedAt, $checkStartedNs, 'ok', count($detected));
                } catch (\Throwable $exception) {
                    $active[] = $this->report('monitor:'.$check.'_check_failed', 'monitor_check_failed', 'P1', 'La comprobación '.$check.' no pudo completarse.', ['check' => $check]);
                    $checks[$check] = $this->checkResult($check, $checkStartedAt, $checkStartedNs, 'failed', 1);
                }
            }

            $fingerprints = array_merge(array_column($active, 'fingerprint'), $this->activeFingerprintAliases);
            $completedChecks = collect($checks)
                ->flatMap(fn (array $result) => $result['recovery_scopes'] ?? [])
                ->unique()
                ->values()
                ->all();
            try {
                $this->incidents->recoverAbsent($fingerprints, $completedChecks);
            } catch (\Throwable $exception) {
                Log::warning('operational_monitor.recovery_failed', ['reason' => 'incident_store_unavailable']);
            }

            $failedChecks = collect($checks)->where('status', 'failed')->count();
            $partialChecks = collect($checks)->where('status', 'partial')->count();
            $status = $failedChecks > 0 ? 'failed' : (count($active) > 0 || $partialChecks > 0 ? 'degraded' : 'healthy');
            $durationMs = (int) floor((hrtime(true) - $startedNs) / 1_000_000);

            try {
                OperationalMonitorRun::query()->create([
                    'status' => $status,
                    'checks' => $checks,
                    'active_incident_count' => count($active),
                    'started_at' => $startedAt,
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('operational_monitor.run_unavailable', ['reason' => 'monitor_run_store_unavailable']);
            }

            return ['enabled' => true, 'active' => $active, 'checks' => $checks, 'status' => $status, 'skipped' => false];
        } finally {
            $lock->release();
        }
    }

    private function database(): array
    {
        DB::select('select 1');
        return [];
    }

    private function queue(): array
    {
        $active = [];
        if (Schema::hasTable('failed_jobs')) {
            $windowHours = (int) config('operational_monitor.failed_jobs_window_hours');
            $recentCutoff = now()->subHours($windowHours);
            $threshold = (int) config('operational_monitor.failed_jobs_threshold');
            // The current alert deliberately inspects only the configured window.
            // Historical failed jobs remain available as a bounded summary below.
            $recentCount = DB::table('failed_jobs')->where('failed_at', '>=', $recentCutoff)->orderByDesc('id')->limit($threshold + 1)->pluck('id')->count();
            $historicalSample = DB::table('failed_jobs')->where('failed_at', '<', $recentCutoff)->orderByDesc('id')->limit(101)->pluck('id')->count();
            $this->checkDetails['queue'] = [
                'failed_jobs' => [
                    'window_hours' => $windowHours,
                    'recent_count_sample' => $recentCount,
                    'historical_count_sample' => $historicalSample,
                    'historical_is_bounded' => $historicalSample === 101,
                ],
            ];
            if ($recentCount >= $threshold) $active[] = $this->report('monitor:failed_jobs', 'queue_failed_jobs', 'P1', 'Se detectaron trabajos fallidos recientes en la cola.', [
                'recent_count_sample' => $recentCount,
                'window_hours' => $windowHours,
                'historical_count_sample' => $historicalSample,
                'historical_is_bounded' => $historicalSample === 101,
            ]);
        }
        if (config('queue.default') === 'database' && Schema::hasTable(config('queue.connections.database.table', 'jobs'))) {
            $oldest = DB::table(config('queue.connections.database.table', 'jobs'))->where('available_at', '<=', now()->timestamp)->orderBy('available_at')->value('available_at');
            if ($oldest && now()->timestamp - (int) $oldest >= config('operational_monitor.queue_lag_seconds')) $active[] = $this->report('monitor:queue_lag', 'queue_lag', 'P1', 'La cola presenta retraso superior al umbral configurado.', ['lag_seconds' => now()->timestamp - (int) $oldest]);
        }
        return $active;
    }

    private function fiscal(): array
    {
        $active = [];
        $cutoff = now()->subMinutes((int) config('operational_monitor.fel_stale_minutes'));
        $ignoredTestBusinessIds = config('operational_monitor.ignored_test_business_ids', []);

        $intentRows = FiscalDocumentIntent::query()
            ->when($ignoredTestBusinessIds !== [], function ($query) use ($ignoredTestBusinessIds): void {
                $query->where(function ($query) use ($ignoredTestBusinessIds): void {
                    $query->whereNotIn('business_id', $ignoredTestBusinessIds)
                        ->orWhere('environment', '!=', 'test')
                        ->orWhereNull('environment')
                        ->orWhereNull('business_id');
                });
            })
            ->whereIn('status', [FiscalDocumentIntent::PENDING, FiscalDocumentIntent::UNKNOWN, FiscalDocumentIntent::CERTIFIED, FiscalDocumentIntent::RETRYABLE])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(self::FISCAL_SAMPLE_LIMIT + 1)
            ->get(['id', 'business_id', 'environment', 'internal_reference', 'updated_at']);
        $staleComplete = $intentRows->count() <= self::FISCAL_SAMPLE_LIMIT;
        $intents = $intentRows->take(self::FISCAL_SAMPLE_LIMIT);
        foreach ($intents->groupBy(fn (FiscalDocumentIntent $intent) => $intent->business_id.':'.$this->fiscalEnvironment($intent->environment)) as $group) {
            $first = $group->first();
            $environment = $this->fiscalEnvironment($first->environment);
            $active[] = $this->report('monitor:fel_stale:'.$first->business_id.':'.$environment, 'fel_stale_intents', 'P0', 'Existen intenciones fiscales pendientes de verificación fuera del umbral.', $this->fiscalMetadata($group, $environment, ! $staleComplete), (int) $first->business_id);
            $this->activeFingerprintAliases[] = 'monitor:fel_stale:'.$first->business_id;
        }

        $criticalRows = FelIncident::query()
            ->with(['sale.fiscalDocumentIntent:id,business_id,finalized_sale_id,environment'])
            ->whereIn('status', ['open', 'review'])
            ->whereIn('severity', ['critical', 'P0'])
            ->orderBy('id')
            ->limit(self::FISCAL_SAMPLE_LIMIT + 1)
            ->get(['id', 'business_id', 'sale_id', 'internal_reference', 'metadata', 'created_at']);
        $criticalComplete = $criticalRows->count() <= self::FISCAL_SAMPLE_LIMIT;
        $critical = $criticalRows->take(self::FISCAL_SAMPLE_LIMIT);
        $intentIds = $critical->pluck('metadata.intent_id')->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)->unique()->values();
        $linkedIntents = FiscalDocumentIntent::query()->whereIn('id', $intentIds)->get(['id', 'business_id', 'finalized_sale_id', 'environment'])->keyBy('id');
        foreach ($critical->groupBy(function (FelIncident $incident) use ($linkedIntents): string {
            $environment = $this->fiscalEnvironmentForIncident($incident, $linkedIntents);
            return $incident->business_id.':'.$environment;
        }) as $group) {
            $first = $group->first();
            $environment = $this->fiscalEnvironmentForIncident($first, $linkedIntents);
            $active[] = $this->report('monitor:fel_critical:'.$first->business_id.':'.$environment, 'fel_critical_incidents', 'P0', 'Existen incidencias FEL críticas pendientes de revisión.', $this->felIncidentMetadata($group, $environment, ! $criticalComplete), (int) $first->business_id);
            $this->activeFingerprintAliases[] = 'monitor:fel_critical:'.$first->business_id;
        }

        $recoveryScopes = ['fiscal_check'];
        if ($staleComplete) $recoveryScopes[] = 'fiscal_stale';
        if ($criticalComplete) $recoveryScopes[] = 'fiscal_critical';
        $this->checkDetails['fiscal'] = [
            'complete' => $staleComplete && $criticalComplete,
            'recovery_scopes' => $recoveryScopes,
            'sources' => [
                'stale_intents' => ['complete' => $staleComplete, 'sample_limit' => self::FISCAL_SAMPLE_LIMIT],
                'critical_incidents' => ['complete' => $criticalComplete, 'sample_limit' => self::FISCAL_SAMPLE_LIMIT],
            ],
        ];
        return $active;
    }

    private function checkResult(string $check, \Carbon\CarbonInterface $startedAt, int $startedNs, string $status, int $activeIncidents): array
    {
        $finishedAt = now();
        $details = $this->checkDetails[$check] ?? [];
        $complete = (bool) ($details['complete'] ?? true);
        return [
            'status' => $status === 'failed' ? 'failed' : ($complete ? 'ok' : 'partial'),
            'result' => $status === 'failed' ? 'failed' : ($complete ? ($activeIncidents > 0 ? 'incidents' : 'healthy') : 'partial'),
            'active_incidents' => $activeIncidents,
            'started_at' => $startedAt->copy()->utc()->toIso8601String(),
            'finished_at' => $finishedAt->copy()->utc()->toIso8601String(),
            'duration_ms' => (int) floor((hrtime(true) - $startedNs) / 1_000_000),
            'details' => $details,
            'recovery_scopes' => $status === 'failed' ? [] : ($details['recovery_scopes'] ?? [$check]),
        ];
    }

    private function fiscalEnvironment(mixed $environment): string
    {
        return in_array($environment, ['test', 'production'], true) ? $environment : 'unknown';
    }

    private function fiscalEnvironmentForIncident(FelIncident $incident, $linkedIntents): string
    {
        $intent = $incident->sale?->fiscalDocumentIntent;
        if ($intent && (int) $intent->business_id === (int) $incident->business_id) {
            return $this->fiscalEnvironment($intent->environment);
        }

        $intentId = data_get($incident->metadata, 'intent_id');
        $intent = filter_var($intentId, FILTER_VALIDATE_INT) !== false ? $linkedIntents->get((int) $intentId) : null;
        if ($intent && (int) $intent->business_id === (int) $incident->business_id && (! $incident->sale_id || (int) $intent->finalized_sale_id === (int) $incident->sale_id)) {
            return $this->fiscalEnvironment($intent->environment);
        }

        return 'unknown';
    }

    private function fiscalMetadata($group, string $environment, bool $partial): array
    {
        $oldest = $group->min('updated_at');
        return [
            'environment' => $environment,
            'count_sample' => $group->count(),
            'sample_is_partial' => $partial,
            'oldest_age_seconds' => $oldest ? (int) now()->diffInSeconds($oldest, true) : null,
            'operations' => $group->take(10)->map(fn (FiscalDocumentIntent $intent) => ['intent_id' => $intent->id, 'internal_reference' => $intent->internal_reference])->values()->all(),
        ];
    }

    private function felIncidentMetadata($group, string $environment, bool $partial): array
    {
        $oldest = $group->min('created_at');
        return [
            'environment' => $environment,
            'count_sample' => $group->count(),
            'sample_is_partial' => $partial,
            'oldest_age_seconds' => $oldest ? (int) now()->diffInSeconds($oldest, true) : null,
            'operations' => $group->take(10)->map(fn (FelIncident $incident) => ['fel_incident_id' => $incident->id, 'sale_id' => $incident->sale_id, 'internal_reference' => $incident->internal_reference])->values()->all(),
        ];
    }

    private function host(): array
    {
        $active = [];
        $free = @disk_free_space(storage_path());
        if ($free !== false && $free / 1048576 < config('operational_monitor.disk_min_free_mb')) $active[] = $this->report('monitor:disk_free', 'disk_low', 'P0', 'El espacio libre de disco está por debajo del umbral.', ['free_mb' => (int) ($free / 1048576)]);
        $meminfo = is_readable('/proc/meminfo') ? (string) @file_get_contents('/proc/meminfo') : '';
        if ($meminfo !== '') {
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $memory);
            preg_match('/SwapFree:\s+(\d+)/', $meminfo, $swap);
            if (isset($memory[1]) && $memory[1] / 1024 < config('operational_monitor.memory_min_available_mb')) $active[] = $this->report('monitor:memory_available', 'memory_low', 'P0', 'La memoria disponible está por debajo del umbral.', ['available_mb' => (int) ($memory[1] / 1024)]);
            if (isset($swap[1]) && $swap[1] / 1024 < config('operational_monitor.swap_min_available_mb')) $active[] = $this->report('monitor:swap_available', 'swap_low', 'P1', 'El swap disponible está por debajo del umbral.', ['available_mb' => (int) ($swap[1] / 1024)]);
        }
        $load = is_readable('/proc/loadavg') ? (float) explode(' ', trim((string) @file_get_contents('/proc/loadavg')))[0] : null;
        if ($load !== null && $load > config('operational_monitor.cpu_load_max') && $this->confirmed('cpu_load')) $active[] = $this->report('monitor:cpu_load', 'cpu_sustained', 'P1', 'La carga CPU sostenida está por encima del umbral.', ['load_1m' => $load]);
        return $active;
    }

    private function confirmed(string $name): bool
    {
        $key = 'operational-monitor:confirmation:'.$name;
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addMinutes(20));
        return $count >= config('operational_monitor.confirmations');
    }

    private function report(string $fingerprint, string $type, string $severity, string $message, array $metadata = [], ?int $businessId = null): array
    {
        try {
            $this->incidents->observe($fingerprint, $type, $severity, $message, $metadata, $businessId);
        } catch (\Throwable $exception) {
            // A database outage cannot be persisted locally. Keep the monitor
            // best-effort and avoid letting a scheduled diagnostic fail noisy.
            Log::warning('operational_monitor.incident_unavailable', ['type' => $type, 'severity' => $severity]);
        }
        return compact('fingerprint', 'type', 'severity');
    }
}
