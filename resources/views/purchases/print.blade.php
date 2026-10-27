<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante de compra {{ $purchaseNumber }}</title>
    <style>@page { size: Letter; margin: 12mm; }</style>
    @include('print.partials.document-styles')
    <style>
        .purchase-signatures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 60px; margin-top: 50px; break-inside: avoid; page-break-inside: avoid; }
        .purchase-signature { text-align: center; }
        .purchase-signature-line { border-top: 1px solid #0d62ab; margin-bottom: 6px; }
        .document-footer { margin-top: 28px; text-align: center; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
@php
    $company ??= \App\Support\DocumentCompanyHeader::make($business, $purchase->branch, $tenantSetting);
    $subtotal = $purchase->items->sum(fn ($item) => (float) $item->total);
    $paymentMethodLabel = match ($purchase->payment_method) {
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'bank_transfer' => 'Transferencia',
        'check' => 'Cheque',
        'credit' => 'Crédito',
        'other' => 'Otro',
        default => $purchase->payment_method ?: '-',
    };
@endphp

<div class="toolbar no-print">
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<main class="document-page">
    <header class="document-header">
        <div class="document-brand">
            @if (! empty($company['logo_url']))
                <img src="{{ $company['logo_url'] }}" alt="Logo de {{ $company['name'] }}" class="document-logo">
            @endif
            <div class="document-company">
                <h1>{{ $company['name'] ?: 'Empresa' }}</h1>
                @if (! empty($company['address']))<div>{{ $company['address'] }}</div>@endif
                @if (! empty($company['phone']))<div>Tel. {{ $company['phone'] }}</div>@endif
            </div>
        </div>
        <div class="document-title">
            <h1>COMPROBANTE DE COMPRA</h1>
            <strong>{{ $purchaseNumber }}</strong>
        </div>
    </header>

    <section class="document-info">
        <div><span class="label">Fecha</span>{{ $purchase->created_at?->timezone($timezone)->format('d/m/Y H:i') }}</div>
        <div><span class="label">Sucursal destino</span>{{ $purchase->branch?->name ?: '-' }}</div>
        <div><span class="label">Proveedor</span>{{ $purchase->supplier?->name ?: '-' }}</div>
        <div><span class="label">Número interno de compra</span>{{ $purchaseNumber }}</div>
        <div><span class="label">Factura proveedor</span>{{ $purchase->supplier_invoice_number ?: '-' }}</div>
        <div><span class="label">Método de pago</span>{{ $paymentMethodLabel }}</div>
        <div><span class="label">Creado por</span>{{ $purchase->createdBy?->name ?: '-' }}</div>
        @if ($purchase->note)
            <div><span class="label">Nota</span>{{ $purchase->note }}</div>
        @endif
    </section>

    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th class="right">Cantidad</th>
                <th class="right">Costo unitario</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($purchase->items as $item)
                <tr>
                    <td>{{ $item->product?->code ?: $item->product?->barcode ?: '-' }}</td>
                    <td>{{ $item->product_name }}</td>
                    <td class="right">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="right">Q{{ number_format((float) $item->unit_cost, 2) }}</td>
                    <td class="right">Q{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="document-summary">
        <div></div>
        <table>
            <tr><td>Subtotal</td><td class="right">Q{{ number_format($subtotal, 2) }}</td></tr>
            <tr><td><strong>Total</strong></td><td class="right total">Q{{ number_format((float) $purchase->total, 2) }}</td></tr>
        </table>
    </section>

    <section class="purchase-signatures">
        <div class="purchase-signature"><div class="purchase-signature-line"></div>Recibido por</div>
        <div class="purchase-signature"><div class="purchase-signature-line"></div>Autorizado por</div>
    </section>

    <footer class="document-footer">Documento interno generado por Blunk</footer>
</main>

<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 300);
});
</script>
</body>
</html>
