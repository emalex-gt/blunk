<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; }
h1 { font-size: 17px; margin: 0 0 4px; } h2 { font-size: 12px; margin: 18px 0 8px; }
.muted { color: #64748b; } table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
th, td { border: 1px solid #dbe3ef; padding: 6px; vertical-align: top; } th { background: #f1f5f9; text-align: left; }
.right { text-align: right; } .seller { page-break-inside: avoid; }
</style></head><body>
<h1>Preparación global de rutas</h1>
@foreach ($document['sellers'] as $group)
<section class="seller">
    <h2>VENDEDOR: {{ $group['seller']['name'] }}</h2>
    <table><thead><tr><th>Cliente</th><th>Preventa</th><th>Jornada</th><th>Lote</th><th class="right">Total</th></tr></thead><tbody>
    @foreach ($group['orders'] as $order)
        <tr><td>{{ $order['customer']->commercial_name ?? $order['customer']->name ?? '-' }}</td><td>#{{ $order['pre_sale_id'] }}</td><td>#{{ $order['work_day_id'] }}</td><td>#{{ $order['batch_id'] }}</td><td class="right">Q {{ number_format($order['total'], 2) }}</td></tr>
    @endforeach
    </tbody></table>
</section>
@endforeach
</body></html>
