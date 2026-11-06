<?php

namespace App\Services\System;

use App\Models\FelIncident;
use App\Models\FiscalDocumentIntent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalMonitor
{
    public function __construct(private readonly OperationalIncidentManager $incidents) {}

    public function run(): array
    {
        if (! config('operational_monitor.enabled')) return ['enabled' => false, 'active' => []];
        $active = [];
        foreach (['database', 'queue', 'fiscal', 'host'] as $check) {
            try { $active = array_merge($active, $this->{$check}()); } catch (\Throwable $exception) {
                $active[] = $this->report('monitor:'.$check.'_check_failed', 'monitor_check_failed', 'P1', 'La comprobación '.$check.' no pudo completarse.', ['check' => $check]);
            }
        }
        $fingerprints = array_column($active, 'fingerprint');
        $this->incidents->recoverAbsent($fingerprints);
        return ['enabled' => true, 'active' => $active];
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
            $count = DB::table('failed_jobs')->orderByDesc('id')->limit((int) config('operational_monitor.failed_jobs_threshold') + 1)->pluck('id')->count();
            if ($count >= config('operational_monitor.failed_jobs_threshold')) $active[] = $this->report('monitor:failed_jobs', 'queue_failed_jobs', 'P1', 'Se detectaron trabajos fallidos en la cola.', ['count_sample' => $count]);
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
        $intents = FiscalDocumentIntent::query()->whereIn('status', [FiscalDocumentIntent::PENDING, FiscalDocumentIntent::UNKNOWN, FiscalDocumentIntent::CERTIFIED, FiscalDocumentIntent::RETRYABLE])->where('updated_at', '<=', $cutoff)->orderBy('id')->limit(101)->get(['id', 'business_id']);
        foreach ($intents->groupBy('business_id') as $businessId => $group) $active[] = $this->report('monitor:fel_stale:'.$businessId, 'fel_stale_intents', 'P0', 'Existen intenciones fiscales pendientes de verificación fuera del umbral.', ['count_sample' => $group->count()], (int) $businessId);
        $critical = FelIncident::query()->whereIn('status', ['open', 'review'])->whereIn('severity', ['critical', 'P0'])->orderBy('id')->limit(101)->get(['id', 'business_id']);
        foreach ($critical->groupBy('business_id') as $businessId => $group) $active[] = $this->report('monitor:fel_critical:'.$businessId, 'fel_critical_incidents', 'P0', 'Existen incidencias FEL críticas pendientes de revisión.', ['count_sample' => $group->count()], (int) $businessId);
        return $active;
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
        $this->incidents->observe($fingerprint, $type, $severity, $message, $metadata, $businessId);
        return compact('fingerprint', 'type', 'severity');
    }
}
