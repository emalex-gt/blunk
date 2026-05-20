<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cierre de caja #{{ $session->id }}</title>
    <style>
        @page {
            size: {{ $receiptFormat === 'ticket' ? '80mm auto' : $paperSize }};
            margin: {{ $receiptFormat === 'ticket' ? '3mm' : '12mm' }};
        }

        body {
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
            font-family: Arial, sans-serif;
            font-size: {{ $receiptFormat === 'ticket' ? '12px' : '14px' }};
        }

        .page {
            width: {{ $receiptFormat === 'ticket' ? '74mm' : '100%' }};
            max-width: {{ $receiptFormat === 'ticket' ? '74mm' : '900px' }};
            margin: 0 auto;
            background: white;
            padding: {{ $receiptFormat === 'ticket' ? '8px' : '28px' }};
        }

        .no-print { margin: 12px auto; max-width: 900px; text-align: center; }
        .print-button { border: 1px solid #cbd5e1; background: white; border-radius: 10px; padding: 8px 14px; cursor: pointer; }
        .center { text-align: center; }
        .logo { max-height: {{ $receiptFormat === 'ticket' ? '44px' : '70px' }}; max-width: 180px; object-fit: contain; }
        h1 { margin: 14px 0 10px; font-size: {{ $receiptFormat === 'ticket' ? '16px' : '24px' }}; }
        .muted { color: #64748b; }
        .line { border-top: 1px solid #e2e8f0; margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: {{ $receiptFormat === 'ticket' ? '4px 0' : '9px 8px' }}; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        th { color: #64748b; font-size: {{ $receiptFormat === 'ticket' ? '10px' : '11px' }}; text-transform: uppercase; }
        .right { text-align: right; white-space: nowrap; }
        .total { font-size: {{ $receiptFormat === 'ticket' ? '14px' : '18px' }}; font-weight: bold; }
        .negative { color: #dc2626; }
        .positive { color: #047857; }

        @media print {
            body { background: white; }
            .page { box-shadow: none; padding: 0; }
            .no-print { display: none; }
            .negative, .positive { color: #000; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-button" onclick="window.print()">Imprimir cierre</button>
    </div>

    <main class="page">
        <header class="center">
            @if (! empty($company['logo_url']))
                <img class="logo" src="{{ $company['logo_url'] }}" alt="{{ $company['name'] }}">
            @endif
            <h1>Cierre de caja</h1>
            <div><strong>{{ $company['name'] }}</strong></div>
            @if (! empty($company['tax_id']))
                <div class="muted">{{ $company['tax_id'] }}</div>
            @endif
            @if (! empty($company['address']))
                <div class="muted">{{ $company['address'] }}</div>
            @endif
            @if (! empty($company['phone']))
                <div class="muted">{{ $company['phone'] }}</div>
            @endif
        </header>

        <div class="line"></div>

        <table>
            <tbody>
                <tr><td>Cierre</td><td class="right">#{{ $session->id }}</td></tr>
                <tr><td>Abierta</td><td class="right">{{ $session->opened_at?->copy()->timezone($timezone)->format('d/m/Y H:i') }}</td></tr>
                <tr><td>Cerrada</td><td class="right">{{ $session->closed_at?->copy()->timezone($timezone)->format('d/m/Y H:i') }}</td></tr>
                <tr><td>Abierta por</td><td class="right">{{ $session->openedBy?->name ?? '-' }}</td></tr>
                <tr><td>Cerrada por</td><td class="right">{{ $session->closedBy?->name ?? '-' }}</td></tr>
            </tbody>
        </table>

        <div class="line"></div>

        <table>
            <tbody>
                <tr><td>Monto inicial</td><td class="right">{{ formatMoney($session->opening_amount, $business?->country) }}</td></tr>
                <tr><td>Ventas en efectivo</td><td class="right positive">{{ formatMoney($summary['cash_sales'], $business?->country) }}</td></tr>
                <tr><td>Anulaciones en efectivo</td><td class="right negative">-{{ formatMoney($summary['cash_sale_cancellations'], $business?->country) }}</td></tr>
                <tr><td>Gastos</td><td class="right negative">-{{ formatMoney($summary['expenses'], $business?->country) }}</td></tr>
                <tr><td>Compras pagadas de caja</td><td class="right negative">-{{ formatMoney($summary['cash_purchases'], $business?->country) }}</td></tr>
                <tr><td class="total">Efectivo esperado</td><td class="right total">{{ formatMoney($session->expected_cash, $business?->country) }}</td></tr>
                <tr><td class="total">Efectivo contado</td><td class="right total">{{ formatMoney($session->counted_cash, $business?->country) }}</td></tr>
                <tr>
                    <td class="total">Diferencia</td>
                    <td class="right total {{ (float) $session->difference < 0 ? 'negative' : 'positive' }}">
                        {{ formatMoney($session->difference, $business?->country) }}
                    </td>
                </tr>
            </tbody>
        </table>

        @if ($session->notes || $session->closing_notes)
            <div class="line"></div>
            @if ($session->notes)
                <p><strong>Notas:</strong><br>{{ $session->notes }}</p>
            @endif
            @if ($session->closing_notes)
                <p><strong>Nota de cierre:</strong><br>{{ $session->closing_notes }}</p>
            @endif
        @endif

        <div class="line"></div>
        <h2 style="font-size: {{ $receiptFormat === 'ticket' ? '13px' : '16px' }};">Resumen de movimientos</h2>
        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Descripción</th>
                    <th class="right">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movements as $movement)
                    <tr>
                        <td>{{ match($movement->type) {
                            'opening' => 'Apertura',
                            'sale_cash' => 'Venta',
                            'sale_cash_cancel' => 'Anulación',
                            'expense' => 'Gasto',
                            'purchase_cash' => 'Compra',
                            default => 'Movimiento',
                        } }}</td>
                        <td>{{ $movement->description }}</td>
                        <td class="right {{ (float) $movement->amount < 0 ? 'negative' : 'positive' }}">
                            {{ formatMoney($movement->amount, $business?->country) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </main>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
