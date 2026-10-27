<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $quotation->visible_number }}</title>
    <style>@page { size: {{ ($business->country ?? 'GT') === 'GT' ? 'Letter' : 'A4' }}; margin: 12mm; }</style>
    @include('print.partials.document-styles')
    <style>.quotation-customer { grid-template-columns: 1fr; } .quotation-total { margin-top: 14px; text-align: right; }</style>
</head>
<body>
<div class="toolbar no-print"><button type="button" onclick="window.close()">Cerrar</button> @include('print.partials.pdf-download-button', ['target' => '.document-page', 'filename' => 'cotizacion-'.$quotation->visible_number.'.pdf', 'kind' => 'document', 'paper' => ($business->country ?? 'GT') === 'GT' ? 'letter' : 'a4', 'marginMm' => 12]) <button type="button" class="primary" onclick="window.print()">Imprimir</button></div>
<main class="document-page">
<header class="document-header">
    <div class="document-brand">
        @if(!empty($company['logo_url']))<img class="document-logo" src="{{ $company['logo_url'] }}" alt="{{ $company['name'] ?? $business->name }}">@endif
        <div class="document-company"><h1>{{ $company['name'] ?? $business->name }}@if($quotation->branch) - {{ $quotation->branch->name }}@endif</h1><div>{{ $company['address'] ?? '' }}</div><div>{{ $company['phone'] ?? '' }}</div></div>
    </div>
    <div class="document-title"><h1>COTIZACIÓN</h1><strong>{{ $quotation->visible_number }}</strong><div>Emisión: {{ $quotation->issued_at?->timezone($timezone)->format('d/m/Y H:i') }}</div><div>Válida hasta: {{ $quotation->valid_until?->format('d/m/Y') }}</div></div>
</header>
@if($quotation->status === \App\Models\Quotation::CANCELLED)<div class="cancelled">COTIZACIÓN ANULADA</div>@endif
<section class="document-info quotation-customer"><div><span class="label">Cliente</span><div>{{ $quotation->customer_commercial_name ?: $quotation->customer_name ?: 'Cliente no especificado' }}</div><div>{{ $quotation->customer_doc_type }} {{ $quotation->customer_doc_number }}</div><div>{{ $quotation->customer_address }}</div>@if($quotation->customer_address_reference)<div>Referencia: {{ $quotation->customer_address_reference }}</div>@endif<div>{{ $quotation->customer_phone }}</div></div></section>
<table><thead><tr><th>Producto</th><th class="right">Cantidad</th><th class="right">Precio unitario</th><th class="right">Total</th></tr></thead><tbody>
@foreach($quotation->items as $item)<tr><td>{{ $item->product_name }}</td><td class="right">{{ $item->quantity }}</td><td class="right">{{ formatMoney($item->unit_price, $business->country) }}</td><td class="right">{{ formatMoney($item->total, $business->country) }}</td></tr>@endforeach
</tbody></table>
@if((float) $quotation->discount_amount > 0)
<p class="right">Subtotal: {{ formatMoney($quotation->subtotal_before_discount, $business->country) }}</p>
<p class="right">Descuento: -{{ formatMoney($quotation->discount_amount, $business->country) }}</p>
@endif
<p class="total quotation-total">Total: {{ formatMoney($quotation->total, $business->country) }}</p>
@if($quotation->note)<p class="document-box"><strong>Observaciones:</strong> {{ $quotation->note }}</p>@endif
<div class="document-note"><p>Precios válidos hasta el {{ $quotation->valid_until?->format('d/m/Y') }}. Cotización sujeta a disponibilidad de existencias.</p><p><strong>Este documento no constituye factura ni comprobante fiscal.</strong></p></div>
</main>
</body>
</html>
