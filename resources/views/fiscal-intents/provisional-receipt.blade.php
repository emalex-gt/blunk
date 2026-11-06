<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Constancia provisional de operación</title>
    @include('print.partials.ticket-styles')
    <style>
        .temporary-title { font-size: 16px; font-weight: 800; letter-spacing: .05em; margin: 10px 0 6px; text-align: center; }
        .provisional-disclaimer { font-size: 10px; line-height: 1.35; margin: 0 0 10px; text-align: center; }
        .internal-reference { overflow-wrap: anywhere; text-align: right; word-break: break-word; }
        .receipt-table { border-collapse: collapse; font-size: 10px; margin: 8px 0; width: 100%; }
        .receipt-table th { border-bottom: 1px solid #000; font-size: 9px; padding: 4px 1px; text-align: left; }
        .receipt-table td { padding: 4px 1px; vertical-align: top; }
        .receipt-table .number { text-align: right; white-space: nowrap; }
        .totals { margin-top: 8px; }
        .totals .row:last-child { border-top: 1px solid #000; font-size: 13px; margin-top: 4px; padding-top: 5px; }
        .temporary-note { font-size: 9px; line-height: 1.4; margin: 12px 0 0; text-align: center; }
    </style>
</head>
<body>
@php
    $sale = data_get($operation, 'sale', []);
    $items = data_get($operation, 'items', []);
    $country = $intent->business?->country ?: 'GT';
    $felSettings = $intent->business?->tenantFelSetting;
    $businessAddress = $intent->branch?->fel_address ?: $intent->branch?->address ?: $felSettings?->establishment_address;
    $customerAddress = collect([
        data_get($sale, 'customer_address'),
        data_get($sale, 'customer_municipality'),
        data_get($sale, 'customer_department'),
    ])->filter(fn ($value) => filled(trim((string) $value)))->unique()->implode(', ');
    $subtotal = data_get($sale, 'subtotal_before_discount');
    $total = data_get($sale, 'total', 0);
    $discount = data_get($sale, 'discount_amount', 0);
    $internalNumber = data_get($sale, 'business_number') ?: $intent->internal_reference;
    $fiscalStatus = match ($intent->status) {
        \App\Models\FiscalDocumentIntent::FINALIZED => 'Factura FEL certificada posteriormente',
        \App\Models\FiscalDocumentIntent::CERTIFIED => 'Factura FEL certificada; finalización local pendiente',
        \App\Models\FiscalDocumentIntent::REJECTED => 'Certificación FEL rechazada; requiere revisión fiscal',
        default => 'Certificación FEL en verificación',
    };
@endphp
    <div class="toolbar no-print">
        <button type="button" onclick="window.close()">Cerrar</button>
        @include('print.partials.pdf-download-button', ['target' => '.ticket', 'filename' => 'comprobante-provisional-'.$intent->id.'.pdf', 'kind' => 'ticket', 'widthMm' => 80, 'marginMm' => 4])
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>
    <main class="ticket">
        <header class="center">
            <h1>{{ $intent->business?->name ?? 'Empresa' }}</h1>
            @if (filled($felSettings?->issuer_tax_id))
                <p>NIT: {{ $felSettings->issuer_tax_id }}</p>
            @endif
            @if (filled($businessAddress))
                <p>{{ $businessAddress }}</p>
            @endif
        </header>
        <div class="line"></div>
        <h2 class="temporary-title">RECIBO TEMPORAL</h2>
        <p class="provisional-disclaimer">No es factura ni documento tributario</p>
        <p class="provisional-disclaimer"><strong>Estado FEL:</strong> {{ $fiscalStatus }}</p>

        <section>
            <div class="row"><span>No. interno:</span><strong class="internal-reference">{{ $internalNumber }}</strong></div>
            <div class="row"><span>Fecha:</span><span>{{ $intent->created_at?->format('Y-m-d H:i') }}</span></div>
            <div class="row"><span>Cliente:</span><span class="right">{{ data_get($sale, 'customer_name', 'Consumidor final') }}</span></div>
            @if (filled(data_get($sale, 'customer_doc_number')))
                <div class="row"><span>NIT:</span><span>{{ data_get($sale, 'customer_doc_number') }}</span></div>
            @endif
            @if (filled($customerAddress))
                <div class="row"><span>Dirección:</span><span class="right">{{ $customerAddress }}</span></div>
            @endif
        </section>

        <div class="line"></div>
        <table class="receipt-table">
            <thead><tr><th>Cant.</th><th>Descripción</th><th class="number">Precio</th><th class="number">Total</th></tr></thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>{{ number_format((float) data_get($item, 'quantity', 0), 2, '.', ',') }}</td>
                        <td>{{ data_get($item, 'product_name', 'Producto') }}</td>
                        <td class="number">{{ formatMoney(data_get($item, 'unit_price', 0), $country) }}</td>
                        <td class="number">{{ formatMoney(data_get($item, 'total', 0), $country) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Sin líneas disponibles</td></tr>
                @endforelse
            </tbody>
        </table>

        <section class="totals">
            <div class="row"><span>Subtotal</span><strong>{{ formatMoney($subtotal ?? $total, $country) }}</strong></div>
            <div class="row"><span>Descuento</span><strong>{{ formatMoney($discount, $country) }}</strong></div>
            <div class="row"><strong>TOTAL</strong><strong>{{ formatMoney($total, $country) }}</strong></div>
        </section>

        <section class="temporary-note">
            Documento provisional pendiente de certificación FEL. No sustituye la factura electrónica.
        </section>
    </main>
</body>
</html>
