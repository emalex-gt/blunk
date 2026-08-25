<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; }
        h1 { font-size: 17px; margin: 0 0 4px; } h2 { font-size: 12px; margin: 18px 0 8px; }
        .muted { color: #64748b; } table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; text-align: left; } th, td { border: 1px solid #dbe3ef; padding: 7px; vertical-align: top; }
        .right { text-align: right; } .total { font-weight: bold; font-size: 11px; }
    </style>
</head>
<body>
    <h1>{{ $batch->business?->name }}</h1>
    <div class="muted">Preparación consolidada · Lote #{{ $batch->id }} · {{ $batch->branch?->name }} · {{ $batch->zone?->name }}</div>
    <div class="muted">Jornada: {{ $batch->workDay?->work_date?->format('d/m/Y') }} · Preparó: {{ $batch->preparedBy?->name }}</div>
    <h2>Clientes preparados</h2>
    <table>
        <thead><tr><th>Cliente</th><th>Teléfono</th><th>Dirección</th><th class="right">Total</th></tr></thead>
        <tbody>
        @foreach ($customers as $row)
            <tr><td>{{ $row['customer']?->commercial_name ?: $row['customer']?->name ?: '-' }}</td><td>{{ $row['customer']?->phone ?: '-' }}</td><td>{{ $row['customer']?->address ?: '-' }}</td><td class="right">Q {{ number_format($row['total'], 2) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr class="total"><td colspan="3" class="right">Total general</td><td class="right">Q {{ number_format($batch->total_amount, 2) }}</td></tr></tfoot>
    </table>
</body>
</html>
