<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $number }} - Recibo de crédito</title>
    <style>@page { size: Letter; margin: 12mm; }</style>
    @include('print.partials.document-styles')
    <style>.totals { margin-left: auto; width: 320px; } .credit-customer { grid-template-columns: 1fr; }</style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.close()">Cerrar</button>
    @include('print.partials.pdf-download-button', ['target' => '.document-page', 'filename' => 'recibo-credito-'.$number.'.pdf', 'kind' => 'document', 'paper' => 'letter', 'marginMm' => 12])
    <button type="button" class="primary" onclick="window.print()">Imprimir</button>
</div>
<main class="document-page">
    <header class="document-header">
        <div>
            @if($logoUrl)
                <img class="document-logo" src="{{ $logoUrl }}" alt="Logo">
            @else
                <h1>{{ $receipt->business->name }}</h1>
            @endif
            <div><strong>{{ $receipt->business->name }}</strong></div>
            @if($receipt->branch)
                <div class="muted">Sucursal: {{ $receipt->branch->name }}</div>
            @endif
        </div>
        <div class="document-title">
            <h1>RECIBO DE PRODUCTOS A CRÉDITO</h1>
            <div><strong>PENDIENTE DE FACTURAR</strong></div>
            <div class="muted">{{ $number }}</div>
            <div class="muted">{{ $receipt->created_at?->format('d/m/Y H:i') }}</div>
        </div>
    </header>

    <section class="document-info credit-customer">
        <div><strong>Cliente:</strong> {{ $receipt->customer_name }}</div>
        <div><strong>NIT:</strong> {{ $receipt->customer_doc_number }}</div>
        @if($receipt->customer_address)
            <div><strong>Dirección:</strong> {{ $receipt->customer_address }}</div>
        @endif
    </section>

    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th>SKU</th>
                <th class="right">Cantidad</th>
                <th class="right">Precio unitario</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($receipt->lines as $line)
                <tr>
                    <td>{{ $line->product_name }}</td>
                    <td>{{ $line->sku ?: '-' }}</td>
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ formatMoney($line->unit_price, $receipt->business->country) }}</td>
                    <td class="right">{{ formatMoney($line->line_total, $receipt->business->country) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Saldo anterior</td><td class="right">{{ formatMoney($previousPending, $receipt->business->country) }}</td></tr>
        <tr><td>Total recibo</td><td class="right">{{ formatMoney($receipt->total, $receipt->business->country) }}</td></tr>
        <tr><td><strong>Nuevo saldo</strong></td><td class="right"><strong>{{ formatMoney($newPending, $receipt->business->country) }}</strong></td></tr>
    </table>

    @if($receipt->notes)
        <section class="document-box">
            <strong>Nota:</strong> {{ $receipt->notes }}
        </section>
    @endif

    <div class="document-warning">
        Este documento no es factura. Los productos quedan reservados hasta su facturación.
    </div>
</main>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
