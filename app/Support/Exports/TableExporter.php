<?php

namespace App\Support\Exports;

use App\Exports\ArrayTableExport;
use App\Support\Inventory\ProductQuantityPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class TableExporter
{
    public static function download(array $payload, string $format, string $filename): Response
    {
        $filename = str($filename)->slug()->toString() ?: 'reporte';

        if ($format === 'excel') {
            $sheet = [
                [$payload['title']],
                ['Empresa', $payload['businessName']],
                ['Sucursal', $payload['branchName']],
                ['Generado', $payload['generatedAt']],
                ['Filtros', $payload['filters']],
                [],
                $payload['columns'],
                ...$payload['rows'],
            ];

            if (! empty($payload['summary'])) {
                $sheet[] = [];
                $sheet[] = ['Resumen'];
                foreach ($payload['summary'] as $item) {
                    $sheet[] = [$item['label'], $item['value']];
                }
            }

            return Excel::download(new ArrayTableExport(
                $sheet,
                $payload['title'],
                $payload['quantityColumnIndexes'] ?? self::quantityColumnIndexesFromLabels($payload['columns']),
            ), "{$filename}.xlsx");
        }

        return Pdf::loadView('exports.table', $payload)
            ->setPaper('a4', 'landscape')
            ->download("{$filename}.pdf");
    }

    public static function value(mixed $value, ?string $type = null): string|int|float
    {
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($type === 'quantity') {
            return ProductQuantityPolicy::signed($value);
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return (int) $value;
        }

        return is_numeric($value) ? (float) $value : (string) $value;
    }

    /** @return array<int, int> One-based spreadsheet columns containing physical quantities. */
    private static function quantityColumnIndexesFromLabels(array $columns): array
    {
        return collect($columns)
            ->map(fn ($label, int $index) => trim(mb_strtolower((string) $label)) === 'cantidad' ? $index + 1 : null)
            ->filter()
            ->values()
            ->all();
    }

    public static function filters(array $filters): string
    {
        return collect($filters)
            ->reject(fn ($value) => $value === null || $value === '' || $value === 'all')
            ->map(fn ($value, $key) => "{$key}: {$value}")
            ->implode(' | ');
    }
}
