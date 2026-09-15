<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; }
h1 { font-size: 17px; margin: 0 0 4px; } h2 { font-size: 12px; margin: 18px 0 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 14px; } th, td { border: 1px solid #dbe3ef; padding: 6px; } th { background: #f1f5f9; text-align: left; }.right { text-align: right; }
</style></head><body>
<h1>Resumen global de productos</h1>
@foreach ($document['sellers'] as $group)
<section><h2>VENDEDOR: {{ $group['seller']['name'] }}</h2>
<table><thead><tr><th>Código</th><th>Marca</th><th>Producto</th><th class="right">Cantidad</th></tr></thead><tbody>
@foreach ($group['products'] as $row)
<tr><td>{{ $row['product']->code ?? '-' }}</td><td>{{ $row['brand'] ?: '-' }}</td><td>{{ $row['product']->name ?? '-' }}</td><td class="right">{{ number_format($row['quantity'], 2) }}</td></tr>
@endforeach
</tbody></table></section>
@endforeach
</body></html>
