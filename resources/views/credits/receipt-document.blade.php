<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $number }} - Recibo de crédito</title>
    <style>
        @page { size: letter; margin: 16mm; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 0; font-size: 13px; }
        .header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111827; padding-bottom: 14px; }
        .logo { max-width: 160px; max-height: 80px; object-fit: contain; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        .muted { color: #4b5563; }
        .box { border: 1px solid #d1d5db; border-radius: 6px; padding: 12px; margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; }
        th { background: #f3f4f6; font-size: 12px; text-transform: uppercase; }
        .right { text-align: right; }
        .totals { margin-left: auto; width: 320px; }
        .note { border: 1px solid #111827; padding: 10px; margin-top: 18px; text-align: center; font-weight: 700; }
    </style>
</head>
<body>
    <header class="header">
        <div>
            @if($logoUrl)
                <img class="logo" src="{{ $logoUrl }}" alt="Logo">
            @else
                <h1>{{ $receipt->business->name }}</h1>
            @endif
            <div><strong>{{ $receipt->business->name }}</strong></div>
            @if($receipt->branch)
                <div class="muted">Sucursal: {{ $receipt->branch->name }}</div>
            @endif
        </div>
        <div class="right">
            <h1>RECIBO DE PRODUCTOS A CRÉDITO</h1>
            <div><strong>PENDIENTE DE FACTURAR</strong></div>
            <div class="muted">{{ $number }}</div>
            <div class="muted">{{ $receipt->created_at?->format('d/m/Y H:i') }}</div>
        </div>
    </header>

    <section class="box">
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
        <section class="box">
            <strong>Nota:</strong> {{ $receipt->notes }}
        </section>
    @endif

    <div class="note">
        Este documento no es factura. Los productos quedan reservados hasta su facturación.
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
