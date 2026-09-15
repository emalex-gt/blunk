<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { size: 5.5in 8.5in; margin: 0.35in; }
body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 9px; }
.receipt + .receipt { page-break-before: always; }
.receipt { page-break-inside: auto; }
h1 { font-size: 15px; margin: 0 0 4px; } .muted { color: #64748b; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; } th { background: #f1f5f9; text-align: left; }
th, td { border: 1px solid #dbe3ef; padding: 5px; } tr { page-break-inside: avoid; } .right { text-align: right; } .total { font-weight: bold; }
</style></head><body>
@foreach ($document['sellers'] as $group)
@foreach ($group['orders'] as $order)
<section class="receipt">
    <h1>Recibo de preparación</h1>
    <div class="muted">Vendedor: {{ $group['seller']['name'] }}</div>
    <div class="muted">Preventa #{{ $order['pre_sale_id'] }} · Jornada #{{ $order['work_day_id'] }} · Lote #{{ $order['batch_id'] }}</div>
    <p><strong>Cliente:</strong> {{ $order['customer']->commercial_name ?? $order['customer']->name ?? '-' }}<br><strong>Dirección:</strong> {{ $order['customer']->address ?? '-' }}</p>
    <table><thead><tr><th>Producto</th><th class="right">Preparado</th><th class="right">Precio</th><th class="right">Total</th></tr></thead><tbody>
    @foreach ($order['items'] ?? [] as $item)
        <tr><td>{{ $item->product->name ?? '-' }}</td><td class="right">{{ number_format($item->picked_quantity, 2) }}</td><td class="right">Q {{ number_format($item->unit_price, 2) }}</td><td class="right">Q {{ number_format(($item->unit_price * $item->picked_quantity) - (($item->discount / max($item->quantity, 1)) * $item->picked_quantity), 2) }}</td></tr>
    @endforeach
    </tbody><tfoot><tr class="total"><td colspan="3" class="right">Total</td><td class="right">Q {{ number_format($order['total'], 2) }}</td></tr></tfoot></table>
</section>
@endforeach
@endforeach
</body></html>
