<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $quotation->visible_number }}</title>
    <style>
        @page { size: {{ ($business->country ?? 'GT') === 'GT' ? 'Letter' : 'A4' }}; margin: 16mm; }
        body { color: #111827; font: 13px Arial, Helvetica, sans-serif; } .header { display:flex; justify-content:space-between; gap:20px; } .company-logo { display:block; max-width:150px; max-height:64px; margin-bottom:10px; object-fit:contain; object-position:left top; }
        table { border-collapse:collapse; width:100%; margin-top:18px; } th,td { border-bottom:1px solid #d1d5db; padding:8px; text-align:left; }
        .right { text-align:right; } .total { font-size:18px; font-weight:bold; margin-top:14px; text-align:right; }
        .cancelled { border:2px solid #991b1b; color:#991b1b; font-size:20px; font-weight:bold; margin:14px 0; padding:8px; text-align:center; }
        .toolbar { margin-bottom:12px; } @media print { .toolbar { display:none; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.close()">Cerrar</button> <button onclick="window.print()">Imprimir</button></div>
<header class="header">
    <div class="company">
        @if(!empty($company['logo_url']))<img class="company-logo" src="{{ $company['logo_url'] }}" alt="{{ $company['name'] ?? $business->name }}">@endif
        <div><h1>{{ $company['name'] ?? $business->name }}@if($quotation->branch) - {{ $quotation->branch->name }}@endif</h1><div>{{ $company['address'] ?? '' }}</div><div>{{ $company['phone'] ?? '' }}</div></div>
    </div>
    <div class="right"><h1>COTIZACIÓN</h1><strong>{{ $quotation->visible_number }}</strong><div>Emisión: {{ $quotation->issued_at?->timezone($timezone)->format('d/m/Y H:i') }}</div><div>Válida hasta: {{ $quotation->valid_until?->format('d/m/Y') }}</div></div>
</header>
@if($quotation->status === \App\Models\Quotation::CANCELLED)<div class="cancelled">COTIZACIÓN ANULADA</div>@endif
<section><h3>Cliente</h3><div>{{ $quotation->customer_commercial_name ?: $quotation->customer_name ?: 'Cliente no especificado' }}</div><div>{{ $quotation->customer_doc_type }} {{ $quotation->customer_doc_number }}</div><div>{{ $quotation->customer_address }}</div>@if($quotation->customer_address_reference)<div>Referencia: {{ $quotation->customer_address_reference }}</div>@endif<div>{{ $quotation->customer_phone }}</div></section>
<table><thead><tr><th>Producto</th><th class="right">Cantidad</th><th class="right">Precio unitario</th><th class="right">Total</th></tr></thead><tbody>
@foreach($quotation->items as $item)<tr><td>{{ $item->product_name }}</td><td class="right">{{ $item->quantity }}</td><td class="right">{{ formatMoney($item->unit_price, $business->country) }}</td><td class="right">{{ formatMoney($item->total, $business->country) }}</td></tr>@endforeach
</tbody></table>
@if((float) $quotation->discount_amount > 0)
<p class="right">Subtotal: {{ formatMoney($quotation->subtotal_before_discount, $business->country) }}</p>
<p class="right">Descuento: -{{ formatMoney($quotation->discount_amount, $business->country) }}</p>
@endif
<p class="total">Total: {{ formatMoney($quotation->total, $business->country) }}</p>
@if($quotation->note)<p><strong>Observaciones:</strong> {{ $quotation->note }}</p>@endif
<hr><p>Precios válidos hasta el {{ $quotation->valid_until?->format('d/m/Y') }}. Cotización sujeta a disponibilidad de existencias.</p>
<p><strong>Este documento no constituye factura ni comprobante fiscal.</strong></p>
</body>
</html>
