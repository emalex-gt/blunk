<?php

namespace App\Support\Exports;

use App\Exports\ArrayTableExport;
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

            return Excel::download(new ArrayTableExport($sheet, $payload['title']), "{$filename}.xlsx");
        }

        return Pdf::loadView('exports.table', $payload)
            ->setPaper('a4', 'landscape')
            ->download("{$filename}.pdf");
    }

    public static function value(mixed $value): string|int|float
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

        return is_numeric($value) ? (float) $value : (string) $value;
    }

    public static function filters(array $filters): string
    {
        return collect($filters)
            ->reject(fn ($value) => $value === null || $value === '' || $value === 'all')
            ->map(fn ($value, $key) => "{$key}: {$value}")
            ->implode(' | ');
    }
}
