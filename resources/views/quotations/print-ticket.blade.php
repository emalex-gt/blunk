<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $quotation->visible_number }}</title>
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        body { color: #111827; font: 12px Arial, Helvetica, sans-serif; margin: 0; }
        .ticket { margin: 0 auto; max-width: 80mm; padding: 4mm; }
        .center { text-align: center; } .row { display: flex; gap: 8px; justify-content: space-between; }
        .line { border-top: 1px dashed #9ca3af; margin: 8px 0; } .item { margin: 7px 0; }
        .right { text-align: right; } .total { font-size: 17px; font-weight: bold; }
        .cancelled { border: 1px dashed #991b1b; color: #991b1b; font-weight: bold; margin: 8px 0; padding: 6px; text-align: center; }
        .toolbar { margin: 10px auto; text-align: center; } button { padding: 6px 10px; }
        @media print { .toolbar { display: none; } .ticket { padding: 0; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.close()">Cerrar</button> <button onclick="window.print()">Imprimir</button></div>
<main class="ticket">
    <header class="center">
        <strong>{{ $company['name'] ?? $business->name }}</strong>
        @if(!empty($company['address']))<div>{{ $company['address'] }}</div>@endif
        @if(!empty($company['phone']))<div>{{ $company['phone'] }}</div>@endif
        @if($quotation->branch)<div>Sucursal: {{ $quotation->branch->name }}@if($quotation->branch->code) ({{ $quotation->branch->code }})@endif</div>@endif
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
