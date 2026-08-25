<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; }
        h1 { font-size: 17px; margin: 0 0 4px; } .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; } th { background: #f1f5f9; text-align: left; }
        th, td { border: 1px solid #dbe3ef; padding: 7px; } .right { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $batch->business?->name }}</h1>
    <div class="muted">Productos preparados · Lote #{{ $batch->id }} · {{ $batch->branch?->name }} · {{ $batch->zone?->name }}</div>
    <table>
        <thead><tr><th>Marca</th><th>Producto</th><th>Código</th><th class="right">Cantidad preparada</th></tr></thead>
        <tbody>
        @foreach ($products as $row)
            <tr><td>{{ $row['brand'] ?: '-' }}</td><td>{{ $row['product']?->name ?: '-' }}</td><td>{{ $row['product']?->code ?: '-' }}</td><td class="right">{{ number_format($row['quantity'], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
