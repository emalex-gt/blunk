<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class IdempotencyHealthMonitor
{
    private const HIGH_CONFLICT_THRESHOLD = 5;
    private const HIGH_REPLAY_THRESHOLD = 10;

    private const CRITICAL_OPERATIONS = [
        'pos_sale',
        'purchase_store',
        'inventory_transfer_store',
        'credit_payment_store',
        'credit_receipt_store',
        'route_pre_sale_invoice',
    ];

    public function inspect(array $options): array
    {
        $businessId = (int) ($options['business'] ?? 0);

        if ($businessId <= 0 || ! Business::query()->whereKey($businessId)->exists()) {
            throw new InvalidArgumentException('Debes indicar un --business existente.');
        }

        $branchId = filled($options['branch'] ?? null) ? (int) $options['branch'] : null;

        if ($branchId && ! DB::table('branches')->where('business_id', $businessId)->where('id', $branchId)->exists()) {
            throw new InvalidArgumentException('La sucursal indicada no pertenece al negocio.');
        }

        $context = $this->context($options, $businessId, $branchId);
        $base = $this->baseQuery($context);
        $staleQuery = $this->staleQuery($context);
        $stale = $staleQuery->orderBy('locked_at')->orderBy('id')->get()->map(fn ($row) => $this->staleRow($row, $context))->all();
        foreach ($stale as $row) {
            Log::warning('idempotency.stale_detected', [
                'business_id' => $row['business_id'],
                'branch_id' => $row['branch_id'],
                'user_id' => $row['user_id'],
                'operation_type' => $row['operation_type'],
                'idempotency_key_hash' => substr((string) $row['idempotency_key'], 4),
                'status' => $row['status'],
                'severity' => $row['severity'],
            ]);
        }
        $failed = (clone $base)
            ->where('status', IdempotencyService::STATUS_FAILED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->failedRow($row))
            ->all();
        $operations = $this->operationSummary($context);
        $highRetryUsers = $this->highRetrySummary($base, 'user_id');
        $highRetryBranches = $this->highRetrySummary($base, 'branch_id');

        $summary = [
            'total_keys' => (clone $base)->count(),
            'completed_count' => (clone $base)->where('status', IdempotencyService::STATUS_COMPLETED)->count(),
            'processing_count' => (clone $base)->where('status', IdempotencyService::STATUS_PROCESSING)->count(),
            'failed_count' => count($failed),
            'replay_count' => (int) ((clone $base)->sum('replay_count') ?: 0),
            'payload_conflict_count' => (int) ((clone $base)->sum('conflict_count') ?: 0),
            'stale_processing_count' => count($stale),
            'operations_by_type' => count($operations),
            'users_with_most_retries' => count($highRetryUsers),
            'branches_with_most_retries' => count($highRetryBranches),
        ];
        $hasCritical = collect($stale)->contains(fn (array $row) => $row['severity'] === 'critical');
        $hasWarnings = $summary['stale_processing_count'] > 0
            || $summary['failed_count'] > 0
            || collect($operations)->contains(fn (array $row) => $row['conflict_count'] >= self::HIGH_CONFLICT_THRESHOLD || $row['replay_count'] >= self::HIGH_REPLAY_THRESHOLD);
        $reportPath = ! empty($options['report'])
            ? $this->writeReport($businessId, $context, $summary, $operations, $stale, $failed, $highRetryUsers, $highRetryBranches)
            : null;

        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'from' => $context['from'],
            'to' => $context['to'],
            'stale_minutes' => $context['stale_minutes'],
            'summary' => $summary,
            'operations' => $operations,
            'stale' => $stale,
            'failed' => $failed,
            'high_retry_users' => $highRetryUsers,
            'high_retry_branches' => $highRetryBranches,
            'has_critical' => $hasCritical,
            'has_warnings' => $hasWarnings,
            'report_path' => $reportPath,
        ];
    }

    private function context(array $options, int $businessId, ?int $branchId): array
    {
        $hasDateRange = filled($options['from'] ?? null) || filled($options['to'] ?? null);
        $hours = max(1, (int) ($options['hours'] ?? 24));

        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'operation' => filled($options['operation'] ?? null) ? (string) $options['operation'] : null,
            'from' => filled($options['from'] ?? null)
                ? Carbon::parse((string) $options['from'])->startOfDay()
                : ($hasDateRange ? null : now()->subHours($hours)),
            'to' => filled($options['to'] ?? null)
                ? Carbon::parse((string) $options['to'])->endOfDay()
                : now(),
            'stale_minutes' => max(1, (int) ($options['stale_minutes'] ?? 10)),
        ];
    }

    private function baseQuery(array $context): Builder
    {
        $query = DB::table('operation_idempotency_keys')
            ->where('business_id', $context['business_id']);

        if ($context['branch_id']) {
            $query->where('branch_id', $context['branch_id']);
        }

        if ($context['operation']) {
            $query->where('operation_type', $context['operation']);
        }

        if ($context['from']) {
            $query->where('created_at', '>=', $context['from']);
        }

        if ($context['to']) {
            $query->where('created_at', '<=', $context['to']);
        }

        return $query;
    }

    private function staleQuery(array $context): Builder
    {
        $cutoff = now()->subMinutes($context['stale_minutes']);

        return $this->baseQuery($context)
            ->where('status', IdempotencyService::STATUS_PROCESSING)
            ->where(function (Builder $query) use ($cutoff) {
                $query->where('locked_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff) {
                        $query->whereNull('locked_at')->where('created_at', '<=', $cutoff);
                    });
            });
    }

    private function operationSummary(array $context): array
    {
        $cutoff = now()->subMinutes($context['stale_minutes'])->toDateTimeString();

        return $this->baseQuery($context)
            ->select('operation_type')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'processing' AND COALESCE(locked_at, created_at) <= ? THEN 1 ELSE 0 END) as stale_processing_count", [$cutoff])
            ->selectRaw('COALESCE(SUM(replay_count), 0) as replay_count')
            ->selectRaw('COALESCE(SUM(conflict_count), 0) as conflict_count')
            ->selectRaw('MIN(created_at) as first_seen')
            ->selectRaw('MAX(created_at) as last_seen')
            ->groupBy('operation_type')
            ->orderBy('operation_type')
            ->get()
            ->map(fn ($row) => [
                'operation_type' => $row->operation_type,
                'completed_count' => (int) $row->completed_count,
                'processing_count' => (int) $row->processing_count,
                'failed_count' => (int) $row->failed_count,
                'stale_processing_count' => (int) $row->stale_processing_count,
                'replay_count' => (int) $row->replay_count,
                'conflict_count' => (int) $row->conflict_count,
                'first_seen' => $row->first_seen,
                'last_seen' => $row->last_seen,
            ])
            ->all();
    }

    private function highRetrySummary(Builder $base, string $column): array
    {
        return (clone $base)
            ->select($column)
            ->selectRaw('COALESCE(SUM(replay_count), 0) as replay_count')
            ->selectRaw('COALESCE(SUM(conflict_count), 0) as conflict_count')
            ->selectRaw('COALESCE(SUM(replay_count), 0) + COALESCE(SUM(conflict_count), 0) as retry_signals')
            ->groupBy($column)
            ->havingRaw('COALESCE(SUM(replay_count), 0) + COALESCE(SUM(conflict_count), 0) > 0')
            ->orderByDesc('retry_signals')
            ->limit(25)
            ->get()
            ->map(fn ($row) => [
                $column => $row->{$column},
                'replay_count' => (int) $row->replay_count,
                'conflict_count' => (int) $row->conflict_count,
                'retry_signals' => (int) $row->retry_signals,
            ])
            ->all();
    }

    private function staleRow(object $row, array $context): array
    {
        $startedAt = Carbon::parse($row->locked_at ?: $row->created_at);
        $ageMinutes = max(0, $startedAt->diffInMinutes(now()));
        $critical = $ageMinutes >= 30 && in_array($row->operation_type, self::CRITICAL_OPERATIONS, true);

        return [
            'id' => $row->id,
            'business_id' => $row->business_id,
            'branch_id' => $row->branch_id,
            'user_id' => $row->user_id,
            'operation_type' => $row->operation_type,
            'idempotency_key' => $this->maskedKey($row->idempotency_key),
            'status' => $row->status,
            'created_at' => $row->created_at,
            'locked_at' => $row->locked_at,
            'age_minutes' => $ageMinutes,
            'request_hash' => $row->request_hash,
            'severity' => $critical ? 'critical' : 'warning',
            'recommended_action' => $critical
                ? 'Revisar la operación y sus efectos antes de reintentar o intervenir.'
                : "Verificar si la operación sigue activa; el umbral configurado es {$context['stale_minutes']} minutos.",
        ];
    }

    private function failedRow(object $row): array
    {
        return [
            'id' => $row->id,
            'operation_type' => $row->operation_type,
            'user_id' => $row->user_id,
            'branch_id' => $row->branch_id,
            'status' => $row->status,
            'created_at' => $row->created_at,
            'request_hash' => $row->request_hash,
            'notes' => $row->last_error,
        ];
    }

    private function writeReport(int $businessId, array $context, array $summary, array $operations, array $stale, array $failed, array $users, array $branches): string
    {
        $directory = 'idempotency-health/'.now()->format('Ymd-His')."-business-{$businessId}";
        Storage::disk('local')->makeDirectory($directory);

        $this->writeCsv($directory.'/summary.csv', ['metric', 'value'], [
            ['business_id', $businessId],
            ['branch_id', $context['branch_id']],
            ['from', $context['from']?->toDateTimeString()],
            ['to', $context['to']?->toDateTimeString()],
            ['operation', $context['operation']],
            ['stale_minutes', $context['stale_minutes']],
            ...collect($summary)->map(fn ($value, $metric) => [$metric, $value])->all(),
        ]);
        $this->writeCsv($directory.'/idempotency_summary_by_operation.csv', ['operation_type', 'completed_count', 'processing_count', 'failed_count', 'stale_processing_count', 'replay_count', 'conflict_count', 'first_seen', 'last_seen'], $operations);
        $this->writeCsv($directory.'/stale_processing_keys.csv', ['id', 'business_id', 'branch_id', 'user_id', 'operation_type', 'idempotency_key', 'status', 'created_at', 'locked_at', 'age_minutes', 'request_hash', 'severity', 'recommended_action'], $stale);
        $this->writeCsv($directory.'/failed_idempotency_keys.csv', ['id', 'operation_type', 'user_id', 'branch_id', 'status', 'created_at', 'request_hash', 'notes'], $failed);
        $this->writeCsv($directory.'/high_retry_users.csv', ['user_id', 'replay_count', 'conflict_count', 'retry_signals'], $users);
        $this->writeCsv($directory.'/high_retry_operations.csv', ['operation_type', 'completed_count', 'processing_count', 'failed_count', 'stale_processing_count', 'replay_count', 'conflict_count', 'first_seen', 'last_seen'], array_values(array_filter($operations, fn (array $row) => $row['replay_count'] > 0 || $row['conflict_count'] > 0)));
        $this->writeCsv($directory.'/high_retry_branches.csv', ['branch_id', 'replay_count', 'conflict_count', 'retry_signals'], $branches);

        return storage_path('app/'.$directory);
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            $values = is_array($row) && array_is_list($row)
                ? $row
                : array_map(fn (string $header) => $row[$header] ?? null, $headers);
            fputcsv($stream, $values);
        }

        rewind($stream);
        Storage::disk('local')->put($path, stream_get_contents($stream));
        fclose($stream);
    }

    private function maskedKey(?string $key): ?string
    {
        if (! filled($key)) {
            return null;
        }

        return 'key-'.substr(hash('sha256', $key), 0, 16);
    }
}
