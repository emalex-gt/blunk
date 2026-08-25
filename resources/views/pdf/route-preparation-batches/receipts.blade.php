<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; }
        .receipt { page-break-after: always; } .receipt:last-child { page-break-after: auto; }
        h1 { font-size: 16px; margin: 0 0 4px; } .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; } th { background: #f1f5f9; text-align: left; }
        th, td { border: 1px solid #dbe3ef; padding: 6px; } .right { text-align: right; } .total { font-weight: bold; }
    </style>
</head>
<body>
@foreach ($batch->preSales as $entry)
    @php($preSale = $entry->preSale)
    @if ($preSale)
    <section class="receipt">
        <h1>{{ $batch->business?->name }}</h1>
        <div class="muted">Recibo de preparación · Lote #{{ $batch->id }} · Preventa #{{ $preSale->id }}</div>
        <div class="muted">Ruta: {{ $batch->zone?->name }} · Vendedor: {{ $preSale->seller?->name ?: $batch->workDay?->seller?->name }}</div>
        <p><strong>Cliente:</strong> {{ $preSale->customer?->commercial_name ?: $preSale->customer?->name ?: '-' }}<br><strong>Dirección:</strong> {{ $preSale->customer?->address ?: '-' }}<br><strong>Observación:</strong> {{ $preSale->notes ?: '-' }}</p>
        <table>
            <thead><tr><th>Producto</th><th class="right">Preparado</th><th class="right">Precio</th><th class="right">Total</th></tr></thead>
            <tbody>
            @foreach ($preSale->items->filter(fn ($item) => (float) ($item->picked_quantity ?? 0) > 0) as $item)
                <tr><td>{{ $item->product?->name ?: '-' }}</td><td class="right">{{ number_format($item->picked_quantity, 2) }}</td><td class="right">Q {{ number_format($item->unit_price, 2) }}</td><td class="right">Q {{ number_format(($item->unit_price * $item->picked_quantity) - (($item->discount / max($item->quantity, 1)) * $item->picked_quantity), 2) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr class="total"><td colspan="3" class="right">Total</td><td class="right">Q {{ number_format($entry->total_amount, 2) }}</td></tr></tfoot>
        </table>
    </section>
    @endif
@endforeach
</body>
</html>
