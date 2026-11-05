<?php

namespace App\Services\SuperAdmin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

final class OperationsLogReader
{
    public const PAGE_SIZE = 50;
    private const MAX_DAYS = 14;
    private const MAX_BYTES = 5_242_880;
    private const MAX_LINES = 10_000;
    private const CHUNK_BYTES = 8_192;

    private const EVENTS = [
        'pos.quotation.loaded', 'pos.identity_lookup.verified', 'pos.identity_lookup.rejected',
        'pos.identity_lookup.failed', 'pos.sale.received', 'pos.sale.rejected',
        'pos.sale.operation_completed', 'fel.intent.created', 'fel.certification.started',
        'fel.certification.certified', 'fel.certification.rejected', 'fel.certification.unknown',
        'fel.finalization.completed', 'fel.finalization.failed', 'fel.reconciliation.not_found',
    ];

    private const CONTEXT_FIELDS = [
        'business_id', 'branch_id', 'user_id', 'sale_id', 'intent_id', 'document_type',
        'operation_type', 'status', 'replayed', 'outcome', 'reason_code', 'duration_ms',
        'provider', 'environment', 'attempt', 'has_draft', 'quotation_id', 'customer_id',
    ];

    private bool $readError = false;

    public function read(array $filters): array
    {
        $this->readError = false;
        $offset = max(0, ((int) ($filters['page'] ?? 1) - 1) * self::PAGE_SIZE);
        $required = $offset + self::PAGE_SIZE + 1;
        $events = [];
        $summary = [
            'examined_events' => 0,
            'matching_events' => 0,
            'pos_rejections' => 0,
            'identity_failures' => 0,
            'fel_unknown' => 0,
            'finalization_failures' => 0,
        ];
        $partial = false;
        $examinedLines = 0;
        $examinedBytes = 0;

        foreach ($this->datesDescending($filters['date_from'], $filters['date_to']) as $date) {
            $path = $this->pathForDate($date);
            if ($path === null) {
                continue;
            }

            foreach ($this->linesFromEnd($path, $examinedBytes, $examinedLines, $partial) as $line) {
                $event = $this->parse($line);
                if ($event === null) {
                    continue;
                }

                $summary['examined_events']++;
                if (! $this->matches($event, $filters)) {
                    continue;
                }

                $summary['matching_events']++;
                $this->addSummary($summary, $event['event']);
                $events[] = $event;

                if (count($events) >= $required) {
                    $partial = true;
                    break 2;
                }
            }
        }

        return [
            'events' => array_slice($events, $offset, self::PAGE_SIZE),
            'has_more' => count($events) > $offset + self::PAGE_SIZE,
            'summary' => $summary,
            'partial' => $partial,
            'files_available' => $this->filesAvailable($filters['date_from'], $filters['date_to']),
            'logging_enabled' => (bool) config('logging.operations.enabled', false),
            'read_error' => $this->readError,
        ];
    }

    public function eventNames(): array
    {
        return self::EVENTS;
    }

    public function maxDays(): int
    {
        return self::MAX_DAYS;
    }

    public function normalizeFilters(array $input): array
    {
        $today = now()->startOfDay();
        $from = CarbonImmutable::parse($input['date_from'] ?? $today->copy()->subDays(6)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($input['date_to'] ?? $today->toDateString())->endOfDay();

        if ($to->lessThan($from) || $from->lessThan($today->copy()->subDays(self::MAX_DAYS - 1))) {
            abort(422, 'El intervalo de diagnóstico debe estar dentro de los últimos '.self::MAX_DAYS.' días.');
        }

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'business_id' => $this->nullablePositiveInt($input['business_id'] ?? null),
            'category' => in_array($input['category'] ?? 'all', ['all', 'pos', 'quotations', 'identity', 'fel'], true) ? $input['category'] : 'all',
            'result' => in_array($input['result'] ?? 'all', ['all', 'success', 'warning', 'error'], true) ? $input['result'] : 'all',
            'event' => in_array($input['event'] ?? '', self::EVENTS, true) ? $input['event'] : '',
            'quotation_id' => $this->nullablePositiveInt($input['quotation_id'] ?? null),
            'sale_id' => $this->nullablePositiveInt($input['sale_id'] ?? null),
            'intent_id' => $this->nullablePositiveInt($input['intent_id'] ?? null),
            'user_id' => $this->nullablePositiveInt($input['user_id'] ?? null),
            'reason_code' => preg_match('/^[a-z0-9_.-]{0,80}$/', (string) ($input['reason_code'] ?? '')) ? (string) ($input['reason_code'] ?? '') : '',
            'page' => min(100, max(1, (int) ($input['page'] ?? 1))),
        ];
    }

    private function pathForDate(string $date): ?string
    {
        $directory = realpath(storage_path('logs'));
        $basename = 'blunk-operations-'.$date.'.log';
        $path = storage_path('logs/'.$basename);

        if ($directory === false || ! preg_match('/^blunk-operations-\d{4}-\d{2}-\d{2}\.log$/', $basename)
            || ! is_file($path)) {
            return null;
        }

        if (is_link($path) || ! is_readable($path)) {
            $this->readError = true;

            return null;
        }

        $resolved = realpath($path);

        if ($resolved !== false && dirname($resolved) === $directory) {
            return $resolved;
        }

        $this->readError = true;

        return null;
    }

    private function linesFromEnd(string $path, int &$examinedBytes, int &$examinedLines, bool &$partial): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->readError = true;

            return;
        }

        try {
            $position = filesize($path);
            $buffer = '';

            while ($position > 0) {
                if ($examinedBytes >= self::MAX_BYTES || $examinedLines >= self::MAX_LINES) {
                    $partial = true;
                    return;
                }

                $length = min(self::CHUNK_BYTES, $position, self::MAX_BYTES - $examinedBytes);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if ($chunk === false) {
                    return;
                }

                $examinedBytes += strlen($chunk);
                $parts = explode("\n", $chunk.$buffer);
                $buffer = array_shift($parts) ?? '';

                foreach (array_reverse($parts) as $line) {
                    $examinedLines++;
                    yield trim($line);
                    if ($examinedLines >= self::MAX_LINES) {
                        $partial = true;
                        return;
                    }
                }
            }

            if ($buffer !== '') {
                $examinedLines++;
                yield trim($buffer);
            }
        } finally {
            fclose($handle);
        }
    }

    private function parse(string $line): ?array
    {
        if (! preg_match('/^\[(?<time>[^\]]+)\]\s+[^.]+\.(?<level>[A-Z]+):\s+(?<event>[a-z][a-z0-9_.-]*)(?:\s+(?<json>\{.*\}))?$/', $line, $matches)
            || ! in_array($matches['event'], self::EVENTS, true)) {
            return null;
        }

        $context = isset($matches['json']) ? json_decode($matches['json'], true) : [];
        if (! is_array($context)) {
            $context = [];
        }

        $context = Arr::only($context, self::CONTEXT_FIELDS);
        try {
            $timestamp = CarbonImmutable::parse($matches['time'])->toIso8601String();
        } catch (\Throwable) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'event' => $matches['event'],
            'result' => $this->resultFor($matches['event'], $context),
            'context' => $context,
        ];
    }

    private function matches(array $event, array $filters): bool
    {
        $context = $event['context'];

        if ($filters['business_id'] && (int) ($context['business_id'] ?? 0) !== $filters['business_id']) return false;
        if ($filters['quotation_id'] && (int) ($context['quotation_id'] ?? 0) !== $filters['quotation_id']) return false;
        if ($filters['sale_id'] && (int) ($context['sale_id'] ?? 0) !== $filters['sale_id']) return false;
        if ($filters['intent_id'] && (int) ($context['intent_id'] ?? 0) !== $filters['intent_id']) return false;
        if ($filters['user_id'] && (int) ($context['user_id'] ?? 0) !== $filters['user_id']) return false;
        if ($filters['reason_code'] !== '' && ($context['reason_code'] ?? '') !== $filters['reason_code']) return false;
        if ($filters['event'] !== '' && $event['event'] !== $filters['event']) return false;
        if ($filters['result'] !== 'all' && $event['result'] !== $filters['result']) return false;

        return $filters['category'] === 'all' || $this->categoryFor($event['event']) === $filters['category'];
    }

    private function resultFor(string $event, array $context): string
    {
        if (str_ends_with($event, '.rejected') || str_ends_with($event, '.failed') || $event === 'pos.sale.rejected') return 'error';
        if (str_ends_with($event, '.unknown') || $event === 'fel.reconciliation.not_found') return 'warning';

        return 'success';
    }

    private function categoryFor(string $event): string
    {
        return match (true) {
            str_starts_with($event, 'pos.quotation.') => 'quotations',
            str_starts_with($event, 'pos.identity_lookup.') => 'identity',
            str_starts_with($event, 'fel.') => 'fel',
            default => 'pos',
        };
    }

    private function addSummary(array &$summary, string $event): void
    {
        if ($event === 'pos.sale.rejected') $summary['pos_rejections']++;
        if (in_array($event, ['pos.identity_lookup.rejected', 'pos.identity_lookup.failed'], true)) $summary['identity_failures']++;
        if ($event === 'fel.certification.unknown') $summary['fel_unknown']++;
        if ($event === 'fel.finalization.failed') $summary['finalization_failures']++;
    }

    private function filesAvailable(string $from, string $to): bool
    {
        foreach ($this->datesDescending($from, $to) as $date) {
            if ($this->pathForDate($date) !== null) return true;
        }

        return false;
    }

    private function datesDescending(string $from, string $to): \Generator
    {
        for ($date = CarbonImmutable::parse($to); $date->greaterThanOrEqualTo(CarbonImmutable::parse($from)); $date = $date->subDay()) {
            yield $date->toDateString();
        }
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }
}
