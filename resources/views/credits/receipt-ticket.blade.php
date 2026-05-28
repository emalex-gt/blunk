<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $number }} - Recibo de crédito</title>
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        body { font-family: Arial, sans-serif; margin: 0; color: #111827; font-size: 12px; }
        .center { text-align: center; }
        .logo { max-width: 52mm; max-height: 24mm; object-fit: contain; margin-bottom: 6px; }
        .title { font-weight: 800; font-size: 13px; line-height: 1.25; margin: 8px 0; }
        .muted { color: #4b5563; }
        .line { border-top: 1px dashed #9ca3af; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 0; vertical-align: top; }
        th { border-bottom: 1px solid #d1d5db; font-size: 11px; text-align: left; }
        .right { text-align: right; }
        .note { border: 1px solid #d1d5db; padding: 6px; margin-top: 8px; font-weight: 700; }
    </style>
</head>
<body>
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

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
