<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; } h1 { font-size: 17px; margin: 0 0 4px; } h2 { font-size: 12px; margin: 18px 0 8px; }.muted { color: #64748b; } table { width: 100%; border-collapse: collapse; } th { background: #f1f5f9; text-align: left; } th, td { border: 1px solid #dbe3ef; padding: 7px; vertical-align: top; }.right { text-align: right; }.total { font-weight: bold; font-size: 11px; }.warning { margin: 10px 0; padding: 7px; background: #fef3c7; color: #92400e; }
</style></head><body>
@php($snapshot = $document['snapshot'])
<h1>{{ data_get($snapshot, 'batch.business.name') }}</h1>
<div class="muted">Preparación consolidada · Lote #{{ data_get($snapshot, 'batch.id') }} · {{ data_get($snapshot, 'batch.branch.name') }} · {{ data_get($snapshot, 'batch.zone.name') }}</div>
<div class="muted">Jornada: {{ data_get($snapshot, 'batch.work_day.work_date') }} · Preparó: {{ data_get($snapshot, 'batch.prepared_by.name') }}</div>
@if ($document['legacy'])<div class="warning">Lote anterior al histórico documental. Este documento se reconstruye con datos actuales y puede diferir del original.</div>@endif
<h2>Clientes preparados</h2><table><thead><tr><th>Cliente</th><th>Teléfono</th><th>Dirección</th><th class="right">Total</th></tr></thead><tbody>
@foreach ($snapshot['orders'] ?? [] as $order)<tr><td>{{ data_get($order, 'customer.commercial_name') ?: data_get($order, 'customer.name', '-') }}</td><td>{{ data_get($order, 'customer.phone', '-') }}</td><td>{{ data_get($order, 'customer.address', '-') }}</td><td class="right">Q {{ number_format((float) data_get($order, 'total_amount', 0), 2) }}</td></tr>@endforeach
</tbody><tfoot><tr class="total"><td colspan="3" class="right">Total general</td><td class="right">Q {{ number_format((float) data_get($snapshot, 'batch.total_amount', 0), 2) }}</td></tr></tfoot></table>
</body></html>
