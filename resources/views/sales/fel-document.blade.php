<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura FEL {{ $fel['series'] }}-{{ $fel['number'] }}</title>
    <style>
        @page { size: {{ $paperSize }}; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef2f7;
            color: #111827;
            font: 12px Arial, Helvetica, sans-serif;
        }
        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin: 16px auto 10px;
            max-width: 900px;
        }
        button {
            border: 1px solid #b8c3d1;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-weight: bold;
            padding: 8px 13px;
        }
        button.primary { background: #0d62ab; border-color: #0d62ab; color: white; }
        .page {
            background: white;
            margin: 0 auto 24px;
            max-width: 900px;
            min-height: 10in;
            padding: 24px;
        }
        .header {
            display: grid;
            grid-template-columns: 170px 1fr 210px;
            gap: 18px;
            align-items: start;
        }
        .logo { max-height: 80px; max-width: 165px; object-fit: contain; }
        h1 { color: #0d62ab; font-size: 20px; margin: 0 0 6px; text-align: center; }
        p { margin: 2px 0; }
        .center { text-align: center; }
        .fel-box {
            border: 1px solid #0d62ab;
            border-radius: 6px;
            padding: 8px;
        }
        .fel-box strong { color: #0d62ab; font-size: 13px; }
        .uuid { font-size: 10px; overflow-wrap: anywhere; }
        .issuer {
            border-bottom: 1px solid #0d62ab;
            margin: 10px 0 14px;
            padding-bottom: 6px;
        }
        .buyer {
            border: 1px solid #0d62ab;
            border-radius: 6px;
            display: grid;
            grid-template-columns: 2fr 1fr;
            margin-bottom: 18px;
        }
        .buyer > div { padding: 9px 12px; }
        .buyer > div + div { border-left: 1px solid #0d62ab; }
        .label { color: #0d62ab; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        table { border-collapse: collapse; width: 100%; }
        .lines th {
            background: #0d62ab;
            color: white;
            padding: 8px 7px;
            text-align: left;
            text-transform: uppercase;
        }
        .lines td {
            border-left: 1px solid #0d62ab;
            border-right: 1px solid #0d62ab;
            padding: 8px 7px;
            vertical-align: top;
        }
        .lines tbody tr:last-child td { border-bottom: 1px solid #0d62ab; }
        .right { text-align: right; white-space: nowrap; }
        .summary {
            display: grid;
            grid-template-columns: 1fr 285px;
            gap: 16px;
            margin-top: 13px;
        }
        .summary table td { border-bottom: 1px solid #dbe2ea; padding: 6px; }
        .grand td {
            border-top: 2px solid #0d62ab;
            color: #0d62ab;
            font-size: 17px;
            font-weight: bold;
        }
        .bottom {
            align-items: center;
            border-top: 1px solid #0d62ab;
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 20px;
            margin-top: 24px;
            padding-top: 12px;
        }
        .qr { height: 100px; width: 100px; }
        .certifier {
            border: 1px solid #0d62ab;
            border-radius: 6px;
            padding: 9px 12px;
        }
        .payments { margin-top: 12px; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .page { margin: 0; max-width: none; min-height: 0; padding: 0; }
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
    <button class="primary" type="button" onclick="window.print()">Imprimir</button>
</div>
<main class="page">
    <header class="header">
        <div>
            @if (! empty($company['logo_url']))
                <img class="logo" src="{{ $company['logo_url'] }}" alt="Logo">
            @endif
        </div>
        <div class="center">
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
    <section class="buyer">
        <div>
            <span class="label">Cliente</span>
            <p><strong>{{ $customer['name'] }}</strong></p>
            <p><b>Dirección:</b> {{ $customer['address'] }} {{ $customer['municipality'] }} {{ $customer['department'] }}</p>
        </div>
        <div>
            <span class="label">NIT</span>
            <p><strong>{{ $customer['tax_id'] }}</strong></p>
            <span class="label">Fecha</span>
            <p>{{ $createdAtLocal ?: '-' }}</p>
        </div>
    </section>
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
    <section class="summary">
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
