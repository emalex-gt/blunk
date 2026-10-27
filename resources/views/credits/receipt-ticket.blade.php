<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $number }} - Recibo de crédito</title>
    @include('print.partials.ticket-styles')
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.close()">Cerrar</button>
    @include('print.partials.pdf-download-button', ['target' => '.ticket', 'filename' => 'recibo-credito-'.$number.'.pdf', 'kind' => 'ticket', 'widthMm' => 80, 'marginMm' => 4])
    <button type="button" onclick="window.print()">Imprimir</button>
</div>
<main class="ticket">
    <div class="center">
        @if($logoUrl)
            <img class="logo" src="{{ $logoUrl }}" alt="Logo">
        @endif
        <div><strong>{{ $receipt->business->name }}</strong></div>
        @if($receipt->branch)
            <div class="muted">{{ $receipt->branch->name }}</div>
        @endif
        <div class="title">RECIBO DE PRODUCTOS A CRÉDITO<br>PENDIENTE DE FACTURAR</div>
        <div><strong>{{ $number }}</strong></div>
        <div class="muted">{{ $receipt->created_at?->format('d/m/Y H:i') }}</div>
    </div>

    <div class="line"></div>
    <div><strong>Cliente:</strong> {{ $receipt->customer_name }}</div>
    <div><strong>NIT:</strong> {{ $receipt->customer_doc_number }}</div>
    @if($receipt->customer_address)
        <div><strong>Dirección:</strong> {{ $receipt->customer_address }}</div>
    @endif

    <div class="line"></div>
    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th class="right">Cant.</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($receipt->lines as $line)
                <tr>
                    <td>{{ $line->product_name }}</td>
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ formatMoney($line->line_total, $receipt->business->country) }}</td>
                </tr>
                <tr>
                    <td colspan="3" class="muted">Precio: {{ formatMoney($line->unit_price, $receipt->business->country) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="line"></div>
    <table>
        <tr><td>Saldo anterior</td><td class="right">{{ formatMoney($previousPending, $receipt->business->country) }}</td></tr>
        <tr><td>Total recibo</td><td class="right">{{ formatMoney($receipt->total, $receipt->business->country) }}</td></tr>
        <tr><td><strong>Nuevo saldo</strong></td><td class="right"><strong>{{ formatMoney($newPending, $receipt->business->country) }}</strong></td></tr>
    </table>

    @if($receipt->notes)
        <div class="line"></div>
        <div><strong>Nota:</strong> {{ $receipt->notes }}</div>
    @endif

    <div class="note center">
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
