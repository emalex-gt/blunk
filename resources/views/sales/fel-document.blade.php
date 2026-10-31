<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura FEL {{ $fel['series'] }}-{{ $fel['number'] }}</title>
    <style>@page { size: {{ $paperSize }}; margin: 12mm; }</style>
    @include('print.partials.document-styles')
    <style>
        .document-header { grid-template-columns: 170px minmax(0, 1fr) 210px; }
        .fel-box {
            border: 1px solid #0d62ab;
            border-radius: 6px;
            padding: 8px;
        }
        .fel-box strong { color: #0d62ab; font-size: 13px; }
        .uuid { font-size: 10px; overflow-wrap: anywhere; }
        .issuer { margin: 10px 0 14px; padding-bottom: 6px; border-bottom: 1px solid #0d62ab; }
        .buyer > div + div { border-left: 1px solid #0d62ab; }
        .lines th {
            padding: 8px 7px;
        }
        .lines td {
            border-left: 1px solid #0d62ab;
            border-right: 1px solid #0d62ab;
            padding: 8px 7px;
            vertical-align: top;
        }
        .lines tbody tr:last-child td { border-bottom: 1px solid #0d62ab; }
        .right { white-space: nowrap; }
        .summary { grid-template-columns: minmax(0, 1fr) 285px; }
        .summary table td { border-bottom: 1px solid #dbe2ea; padding: 6px; }
        .grand td {
            border-top: 2px solid #0d62ab;
            color: #0d62ab;
            font-size: 17px;
            font-weight: bold;
        }
        .bottom { display: grid; grid-template-columns: 120px 1fr; gap: 20px; align-items: center; margin-top: 24px; padding-top: 12px; border-top: 1px solid #0d62ab; }
        .qr { height: 100px; width: 100px; }
        .certifier { border: 1px solid #0d62ab; border-radius: 5px; padding: 9px 12px; }
        .payments { margin-top: 12px; }
    </style>
</head>
<body>
@php
    $paymentLabels = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'check' => 'Cheque',
        'mercadopago' => 'MercadoPago',
    ];
    $paymentDetails = function ($payment) {
        $details = $payment->details ?? [];

        return collect(match ($payment->method) {
            'card' => ['Autorización' => $details['authorization'] ?? null],
            'transfer' => ['Banco' => $details['bank'] ?? null, 'Referencia' => $details['transfer_reference'] ?? null],
            'check' => ['Banco' => $details['bank'] ?? null, 'Cheque' => $details['check_number'] ?? null],
            'mercadopago' => ['Referencia' => $details['mercadopago_reference'] ?? null],
            default => [],
        })->filter();
    };
@endphp
<div class="toolbar no-print">
    <button type="button" onclick="window.close()">Cerrar</button>
    @include('print.partials.pdf-download-button', ['target' => '.document-page', 'filename' => 'factura-fel-'.$fel['series'].'-'.$fel['number'].'.pdf', 'kind' => 'document', 'paper' => $paperSize, 'marginMm' => 12])
    @include('print.partials.delivery-print-toggle', ['delivery' => $delivery])
    <button class="primary" type="button" onclick="window.print()">Imprimir</button>
</div>
<main class="document-page">
    @if (($fel['status'] ?? null) === 'cancelled')
        <section class="document-warning" style="margin-bottom: 12px; border-color: #b91c1c; color: #991b1b;">
            <strong>FACTURA FEL ANULADA</strong>
            <p>Fecha de anulación: {{ $fel['cancelled_at'] ?: '-' }}</p>
            @if (! empty($fel['cancellation_reason']))
                <p>Motivo: {{ $fel['cancellation_reason'] }}</p>
            @endif
        </section>
    @endif
    <header class="document-header">
        <div>
            @if (! empty($company['logo_url']))
                <img class="logo" src="{{ $company['logo_url'] }}" alt="Logo">
            @endif
        </div>
        <div class="document-company center">
            <h1>{{ $company['name'] }}</h1>
            @if (! empty($company['establishment_name']))
                <p><strong>{{ $company['establishment_name'] }}</strong></p>
            @endif
            <p>{{ $company['address'] ?: 'Ciudad' }}</p>
            <p>{{ $company['municipality'] }} {{ $company['department'] }}</p>
        </div>
        <div class="fel-box">
            <div class="center"><strong>FACTURA FEL</strong></div>
            <p><b>Serie:</b> {{ $fel['series'] ?: '-' }}</p>
            <p><b>Ref. interna:</b> {{ format_sale_number($sale) }}</p>
            <p><b>Número:</b> {{ $fel['number'] ?: '-' }}</p>
            <p><b>Autorización:</b></p>
            <p class="uuid">{{ $fel['uuid'] }}</p>
        </div>
    </header>
    <div class="issuer">
        <strong>NIT: {{ $company['tax_id'] }}</strong>
        <span style="float:right">Documento Tributario Electrónico</span>
    </div>
    <section class="document-info buyer">
        <div>
            <span class="label">Cliente</span>
            <p><strong>{{ $customer['name'] }}</strong></p>
            @if (filled($customer['address'] ?? null))
                <p><b>Dirección:</b> {{ $customer['address'] }}</p>
            @endif
        </div>
        <div>
            <span class="label">NIT</span>
            <p><strong>{{ $customer['tax_id'] }}</strong></p>
            <span class="label">Fecha</span>
            <p>{{ $createdAtLocal ?: '-' }}</p>
            <span class="label">Le atendió</span>
            <p>{{ $attendedBy }}</p>
        </div>
    </section>
    @include('print.partials.delivery-details', ['delivery' => $delivery])
    <table class="lines">
        <thead>
            <tr>
                <th style="width:12%">Cantidad</th>
                <th>Descripción</th>
                <th class="right" style="width:18%">P. unitario</th>
                <th class="right" style="width:18%">Total</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td class="center">{{ (int) $item->quantity }}</td>
                <td>{{ $item->product_name }}</td>
                <td class="right">{{ formatMoney($item->unit_price, 'GT') }}</td>
                <td class="right">{{ formatMoney($item->total, 'GT') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <section class="document-summary summary">
        <div class="payments">
            <span class="label">Pagos</span>
            @foreach ($payments as $payment)
                <p>{{ $paymentLabels[$payment->method] ?? $payment->method }}: <strong>{{ formatMoney($payment->amount, 'GT') }}</strong></p>
                @foreach ($paymentDetails($payment) as $label => $value)
                    <p>{{ $label }}: {{ $value }}</p>
                @endforeach
            @endforeach
        </div>
        <table>
            @if ($discount > 0)
                <tr><td>Subtotal</td><td class="right">{{ formatMoney($subtotal, 'GT') }}</td></tr>
                <tr><td>Descuento</td><td class="right">-{{ formatMoney($discount, 'GT') }}</td></tr>
            @endif
            <tr><td>IVA incluido</td><td class="right">{{ formatMoney($iva, 'GT') }}</td></tr>
            <tr class="grand"><td>Total</td><td class="right">{{ formatMoney($total, 'GT') }}</td></tr>
        </table>
    </section>
    @if (! empty($visibleFelPhrases))
        <section class="certifier" style="margin-top: 14px;">
            @foreach ($visibleFelPhrases as $phrase)
                <p><strong>{{ $phrase }}</strong></p>
            @endforeach
        </section>
    @endif
    <section class="bottom">
        <img class="qr" src="{{ $fel['qr_url'] }}" alt="Verificación SAT FEL">
        <div class="certifier">
            <div class="label">Datos del certificador</div>
            <p><strong>Certificador NIT {{ $fel['certifier_tax_id'] }}</strong></p>
            <p>{{ $fel['certifier_name'] }}</p>
            <p>Fecha de certificación: {{ $fel['certified_at'] ?: '-' }}</p>
            <p class="uuid">UUID: {{ $fel['uuid'] }}</p>
        </div>
    </section>
</main>
<script>
window.addEventListener('load', function () {
  setTimeout(function () { window.print(); }, 300);
});
</script>
</body>
</html>
