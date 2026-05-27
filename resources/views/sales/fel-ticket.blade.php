<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura FEL {{ $fel['series'] }}-{{ $fel['number'] }}</title>
    <style>
        @page { size: 80mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e5e7eb;
            color: #111;
            font: 10px Arial, Helvetica, sans-serif;
        }
        .toolbar {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin: 10px auto;
            width: 76mm;
        }
        button {
            border: 1px solid #9ca3af;
            border-radius: 5px;
            background: white;
            cursor: pointer;
            font-weight: bold;
            padding: 7px 10px;
        }
        .ticket {
            background: #fff;
            margin: 0 auto 16px;
            padding: 2mm;
            width: 76mm;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        h1 { font-size: 15px; margin: 2px 0; }
        p { margin: 1px 0; }
        .separator {
            border-top: 1px dashed #111;
            margin: 7px 0;
        }
        .section-title {
            font-weight: bold;
            margin: 5px 0;
            text-align: center;
        }
        .fel-name {
            font-size: 11px;
            font-weight: bold;
            margin: 4px 0;
            text-align: center;
            text-transform: uppercase;
        }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 6px;
        }
        .break { overflow-wrap: anywhere; }
        table {
            border-collapse: collapse;
            font-size: 10px;
            width: 100%;
        }
        th {
            border-bottom: 1px solid #111;
            padding: 3px 1px;
            text-align: left;
        }
        td { padding: 3px 1px; vertical-align: top; }
        .money { text-align: right; white-space: nowrap; }
        .total {
            font-size: 12px;
            font-weight: bold;
        }
        .logo {
            display: block;
            max-height: 42px;
            max-width: 120px;
            margin: 0 auto 4px;
            object-fit: contain;
        }
        .muted { color: #374151; font-size: 9px; }
        .qr { display: block; height: 80px; margin: 6px auto; width: 80px; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .ticket { margin: 0; padding: 0; width: 100%; }
        }
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
    <button type="button" onclick="window.print()">Imprimir</button>
</div>
<main class="ticket">
    <header class="center">
        @if (! empty($company['logo_url']))
            <img src="{{ $company['logo_url'] }}" alt="Logo" class="logo">
        @endif
        <div class="fel-name">DOCUMENTO TRIBUTARIO ELECTRÓNICO</div>
        <div class="center"><strong>FACTURA ELECTRÓNICA FEL</strong></div>
        <p>Serie: {{ $fel['series'] ?: '-' }} &nbsp; No. {{ $fel['number'] ?: '-' }}</p>
        <p>Ref: {{ format_sale_number($sale) }}</p>
        <p>Autorización: {{ $fel['uuid'] }}</p>
    </header>

    <div class="separator"></div>
    <div class="section-title">INFORMACIÓN EMISOR</div>

    <div class="fel-name"><strong>{{ $company['name'] }}</strong></div>
    <div class="center">NIT: {{ $company['tax_id'] }}</div>
    <div class="center">{{ $company['address'] ?: 'Ciudad' }}</div>
    @if (! empty($company['municipality']) || ! empty($company['department']))
        <div class="center">{{ $company['municipality'] }} {{ $company['department'] }}</div>
    @endif

    <div class="separator"></div>
    <div class="section-title">INFORMACIÓN COMPRADOR</div>
    <div class="center">{{ $createdAtLocal ?: '-' }}</div>
    <div class="center"><strong>NIT: {{ $customer['tax_id'] }}</strong></div>
    <div class="center"><strong>{{ $customer['name'] }}</strong></div>
    <div class="center">Dirección: {{ $customer['address'] }}</div>
    @if (! empty($customer['municipality']) || ! empty($customer['department']))
        <div class="center">{{ $customer['municipality'] }} {{ $customer['department'] }}</div>
    @endif

    <div class="separator"></div>
    <table>
        <thead>
            <tr>
                <th style="width: 12%;">Cant</th>
                <th>Descripción</th>
                <th class="money">Precio</th>
                <th class="money">Total</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>{{ (int) $item->quantity }}</td>
                <td class="break">{{ $item->product_name }}</td>
                <td class="money">{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="money">{{ number_format((float) $item->total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="separator"></div>
    @if ($discount > 0)
        <div class="row"><span>Subtotal</span><strong>{{ formatMoney($subtotal, 'GT') }}</strong></div>
        <div class="row"><span>Descuento</span><strong>-{{ formatMoney($discount, 'GT') }}</strong></div>
    @endif
    <div class="row"><span>IVA</span><strong>{{ formatMoney($iva, 'GT') }}</strong></div>
    <div class="row total"><span>Total</span><span>{{ formatMoney($total, 'GT') }}</span></div>

    <strong>SUJETO A PAGOS TRIMESTRALES</strong>

    <div class="separator"></div>
    @foreach ($payments as $payment)
        <div class="row">
            <span>{{ $paymentLabels[$payment->method] ?? $payment->method }}</span>
            <strong>{{ formatMoney($payment->amount, 'GT') }}</strong>
        </div>
        @foreach ($paymentDetails($payment) as $label => $value)
            <div class="row muted"><span>{{ $label }}</span><span>{{ $value }}</span></div>
        @endforeach
    @endforeach

    <div class="separator"></div>
    <div class="center">
        <img class="qr" src="{{ $fel['qr_url'] }}" alt="Verificación FEL">
        <strong>DATOS DEL GFACE</strong>
        <p>Certificador NIT {{ $fel['certifier_tax_id'] }}</p>
        <p>{{ $fel['certifier_name'] }}</p>
        <p>Fecha Hora Certificación: {{ $fel['certified_at'] ?: '-' }}</p>
    </div>
</main>
<script>
window.addEventListener('load', function () {
  setTimeout(function () { window.print(); }, 300);
});
</script>
</body>
</html>
