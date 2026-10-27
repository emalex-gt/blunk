<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $quotation->visible_number }}</title>
    @include('print.partials.ticket-styles')
</head>
<body>
<div class="toolbar no-print"><button type="button" onclick="window.close()">Cerrar</button> @include('print.partials.pdf-download-button', ['target' => '.ticket', 'filename' => 'cotizacion-'.$quotation->visible_number.'.pdf', 'kind' => 'ticket', 'widthMm' => 80, 'marginMm' => 4]) <button type="button" onclick="window.print()">Imprimir</button></div>
<main class="ticket">
    <header class="center">
        <strong>{{ $company['name'] ?? $business->name }}@if($quotation->branch) - {{ $quotation->branch->name }}@endif</strong>
        @if(!empty($company['address']))<div>{{ $company['address'] }}</div>@endif
        @if(!empty($company['phone']))<div>{{ $company['phone'] }}</div>@endif
        <h1>COTIZACIÓN</h1>
    </header>
    @if($quotation->status === \App\Models\Quotation::CANCELLED)<div class="cancelled">COTIZACIÓN ANULADA</div>@endif
    <div class="line"></div>
    <div class="row"><strong>Número</strong><span>{{ $quotation->visible_number }}</span></div>
    <div class="row"><span>Fecha</span><span>{{ $quotation->issued_at?->timezone($timezone)->format('d/m/Y H:i') }}</span></div>
    <div class="row"><span>Válida hasta</span><span>{{ $quotation->valid_until?->format('d/m/Y') }}</span></div>
    <div class="row"><span>Cliente</span><span class="right">{{ $quotation->customer_commercial_name ?: $quotation->customer_name ?: 'Cliente no especificado' }}</span></div>
    <div class="line"></div>
    @foreach($quotation->items as $item)
        <div class="item"><strong>{{ $item->product_name }}</strong><div class="row"><span>{{ $item->quantity }} × {{ formatMoney($item->unit_price, $business->country) }}</span><span>{{ formatMoney($item->total, $business->country) }}</span></div></div>
    @endforeach
    <div class="line"></div>
    @if((float) $quotation->discount_amount > 0)
        <div class="row"><span>Subtotal</span><span>{{ formatMoney($quotation->subtotal_before_discount, $business->country) }}</span></div>
        <div class="row"><span>Descuento</span><span>-{{ formatMoney($quotation->discount_amount, $business->country) }}</span></div>
    @endif
    <div class="row total"><span>Total</span><span>{{ formatMoney($quotation->total, $business->country) }}</span></div>
    @if($quotation->note)<p><strong>Observaciones:</strong> {{ $quotation->note }}</p>@endif
    <div class="line"></div>
    <p>Precios válidos hasta el {{ $quotation->valid_until?->format('d/m/Y') }}. Cotización sujeta a disponibilidad de existencias.</p>
    <p><strong>Este documento no constituye factura ni comprobante fiscal.</strong></p>
</main>
</body>
</html>
