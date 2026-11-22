<?php

namespace App\Services\SuperAdmin;

use Carbon\CarbonImmutable;

final class PosFelTraceReport
{
    private const MAX_DAYS = 14;
    private const MAX_LINES = 10000;
    private const REQUIRED_PHASES = [
        'submit_clicked', 'post_finished', 'destination_get_finished',
        'inertia_on_success', 'destination_first_frame', 'print_open_started',
    ];

    /** @return array{traces: array<int, array<string, mixed>>, percentiles: array<int, array<string, mixed>>, partial: bool} */
    public function report(?string $traceId, ?int $businessId, int $days): array
    {
        $events = $this->events($traceId, $businessId, min(max($days, 1), self::MAX_DAYS));

        return [
            ...$this->summarize($events['events']),
            'partial' => $events['partial'],
        ];
    }

    /** @param array<int, array<string, mixed>> $events @return array{traces: array<int, array<string, mixed>>, percentiles: array<int, array<string, mixed>>} */
    public function summarize(array $events): array
    {
        $traces = [];

        foreach ($events as $event) {
            $id = $event['trace_id'];
            $traces[$id] ??= [
                'trace_id' => $id,
                'business_id' => $event['business_id'],
                'environment' => $event['environment'] ?? 'unknown',
                'phases' => [],
            ];
            if ($event['environment'] !== null) {
                $traces[$id]['environment'] = $event['environment'];
            }
            if ($event['duration_ms'] !== null) {
                $traces[$id]['phases'][$event['phase']] = $event['duration_ms'];
            }
        }

        $rows = collect($traces)->map(function (array $trace): array {
            $phases = $trace['phases'];
            $missing = array_values(array_diff(self::REQUIRED_PHASES, array_keys($phases)));

            return [
                ...$trace,
                'click_to_print_ms' => $phases['print_open_started'] ?? null,
                'incomplete' => $missing !== [],
                'missing_phases' => $missing,
            ];
        })->values()->all();

        return [
            'traces' => $rows,
            'percentiles' => $this->percentiles($rows),
        ];
    }

    /** @return array{events: array<int, array<string, mixed>>, partial: bool} */
    private function events(?string $traceId, ?int $businessId, int $days): array
    {
        $events = [];
        $lines = 0;
        $from = CarbonImmutable::now(config('app.timezone'))->subDays($days - 1)->startOfDay();

        for ($date = CarbonImmutable::now(config('app.timezone'))->startOfDay(); $date->greaterThanOrEqualTo($from); $date = $date->subDay()) {
            $path = storage_path('logs/blunk-operations-'.$date->toDateString().'.log');
            if (! is_file($path) || is_link($path) || ! is_readable($path)) {
                continue;
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    if (++$lines > self::MAX_LINES) {
                        return ['events' => $events, 'partial' => true];
                    }
                    if (! preg_match('/pos\.sale\.trace\s+(\{.*\})$/', trim($line), $match)) {
                        continue;
                    }
                    $context = json_decode($match[1], true);
                    if (! is_array($context) || ! is_string($context['trace_id'] ?? null) || ! is_string($context['phase'] ?? null)) {
                        continue;
                    }
                    if ($traceId !== null && $context['trace_id'] !== $traceId) {
                        continue;
                    }
                    if ($businessId !== null && (int) ($context['business_id'] ?? 0) !== $businessId) {
                        continue;
                    }
                    $events[] = [
                        'trace_id' => $context['trace_id'],
                        'phase' => $context['phase'],
                        'duration_ms' => is_numeric($context['duration_ms'] ?? null) ? (float) $context['duration_ms'] : null,
                        'business_id' => (int) ($context['business_id'] ?? 0),
                        'environment' => is_string($context['environment'] ?? null) ? $context['environment'] : null,
                    ];
                }
            } finally {
                fclose($handle);
            }
        }

        return ['events' => $events, 'partial' => false];
    }

    /** @param array<int, array<string, mixed>> $traces */
    private function percentiles(array $traces): array
    {
        $samples = [];
        foreach ($traces as $trace) {
            foreach ($trace['phases'] as $phase => $duration) {
                $samples[$trace['environment']][$phase][] = $duration;
            }
        }

        $rows = [];
        foreach ($samples as $environment => $phases) {
            foreach ($phases as $phase => $durations) {
                sort($durations, SORT_NUMERIC);
                $rows[] = [
                    'environment' => $environment,
                    'phase' => $phase,
                    'count' => count($durations),
                    'median_ms' => $this->percentile($durations, 50),
                    'p90_ms' => $this->percentile($durations, 90),
                    'p95_ms' => $this->percentile($durations, 95),
                ];
            }
        }

        return $rows;
    }

    /** @param array<int, float> $values */
    private function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) return null;
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return round($values[max(0, $index)], 2);
    }
}
