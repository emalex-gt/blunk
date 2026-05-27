<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante {{ format_sale_number($sale) }}</title>
    <style>
        @page {
            size: {{ $paperSize }};
            margin: 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f1f5f9;
            color: #0f172a;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
        }

        .toolbar {
            max-width: 920px;
            margin: 20px auto 12px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        button {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: white;
            color: #334155;
            cursor: pointer;
            font-weight: 700;
            padding: 9px 14px;
        }

        button.primary {
            border-color: #4f46e5;
            background: #4f46e5;
            color: white;
        }

        .receipt {
            max-width: 920px;
            margin: 0 auto 30px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
        }

        .header,
        .info,
        .totals {
            display: flex;
            justify-content: space-between;
            gap: 24px;
        }

        .header {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 22px;
        }

        .brand {
            display: flex;
            gap: 16px;
            min-width: 0;
        }

        .logo {
            height: 78px;
            width: 78px;
            object-fit: contain;
        }

        h1,
        h2,
        p {
            margin: 0;
        }

        h1 {
            font-size: 24px;
            line-height: 1.2;
        }

        .muted {
            color: #64748b;
        }

        .small {
            font-size: 12px;
        }

        .title {
            text-align: right;
            white-space: nowrap;
        }

        .title h2 {
            font-size: 20px;
            text-transform: uppercase;
        }

        .cancelled-badge {
            border: 2px solid #dc2626;
            color: #b91c1c;
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 0.08em;
            margin: 22px 0 14px;
            padding: 14px;
            text-align: center;
        }

        .cancelled-info {
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #7f1d1d;
            margin-bottom: 18px;
            padding: 12px;
        }

        .info {
            border-bottom: 1px solid #e2e8f0;
            padding: 18px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 18px 0;
        }

        th {
            border-bottom: 1px solid #cbd5e1;
            color: #475569;
            font-size: 12px;
            padding: 9px 6px;
            text-align: left;
            text-transform: uppercase;
        }

        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 6px;
        }

        .right {
            text-align: right;
        }

        .payments {
            min-width: 260px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 4px 0;
        }

        .total-box {
            text-align: right;
        }

        .total {
            font-size: 34px;
            font-weight: 800;
            margin-top: 4px;
        }

        .footer {
            border-top: 1px solid #e2e8f0;
            font-weight: 700;
            margin-top: 34px;
            padding-top: 18px;
            text-align: center;
        }

        @media print {
            body {
                background: white;
                color: black;
            }

            .cancelled-badge,
            .cancelled-info {
                border-color: black;
                color: black;
            }

            .no-print {
                display: none !important;
            }

            .receipt {
                border: 0;
                border-radius: 0;
                box-shadow: none;
                margin: 0;
                max-width: none;
                padding: 0;
            }
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
        'bizum' => 'Bizum',
        'other' => 'Otro',
    ];
    $country = $business?->country ?: 'GT';
    $paymentDetailRows = function ($payment) {
        $details = $payment->details ?? [];

        return collect(match ($payment->method) {
            'card' => [
                'Autorización' => $details['authorization'] ?? null,
            ],
            'transfer' => [
                'Banco' => $details['bank'] ?? null,
                'Referencia' => $details['transfer_reference'] ?? null,
            ],
            'check' => [
                'Banco' => $details['bank'] ?? null,
                'Cheque' => $details['check_number'] ?? null,
            ],
            'mercadopago' => [
                'Referencia' => $details['mercadopago_reference'] ?? null,
            ],
            default => [],
        })->filter();
    };
@endphp

<div class="toolbar no-print">
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="primary" onclick="window.print()">Imprimir</button>
</div>

<main class="receipt">
    <header class="header">
        <div class="brand">
            @if (! empty($company['logo_url']))
                <img src="{{ $company['logo_url'] }}" alt="Logo" class="logo">
            @endif
            <div>
                <h1>{{ $company['name'] ?? $business?->name ?? 'Empresa' }}</h1>
                @if (! empty($company['tax_id']))
                    <p class="small">Identificación fiscal: {{ $company['tax_id'] }}</p>
                @endif
                @if (! empty($company['address']))
                    <p class="small">{{ $company['address'] }}</p>
                @endif
                @if (! empty($company['phone']))
                    <p class="small">Teléfono: {{ $company['phone'] }}</p>
                @endif
            </div>
        </div>

        <div class="title">
            <h2>Comprobante de venta</h2>
            <p>Venta {{ format_sale_number($sale) }}</p>
            <p class="small muted">Fecha: {{ $createdAtLocal ?? '-' }}</p>
        </div>
    </header>

    @if ($sale->status === 'cancelled')
        <section class="cancelled-badge">
            VENTA ANULADA
        </section>
        <section class="cancelled-info">
            @if ($sale->cancellation_reason)
                <p><strong>Motivo de anulación:</strong> {{ $sale->cancellation_reason }}</p>
            @endif
            @if (! empty($cancelledAtLocal))
                <p><strong>Fecha de anulación:</strong> {{ $cancelledAtLocal }}</p>
            @endif
            @if ($sale->cancelledBy?->name)
                <p><strong>Anulada por:</strong> {{ $sale->cancelledBy->name }}</p>
            @endif
        </section>
    @endif

    <section class="info">
        <div>
            <strong>Cliente</strong>
            <p>{{ $sale->customer?->name ?? 'Consumidor Final' }}</p>
            @if ($sale->customer?->doc_number)
                <p class="small">{{ $sale->customer?->doc_type ?? 'Documento' }}: {{ $sale->customer?->doc_number }}</p>
            @endif
        </div>
        <div class="right">
            <strong>Usuario</strong>
            <p>{{ $sale->createdBy?->name ?? '-' }}</p>
        </div>
    </section>

    <table>
        <thead>
        <tr>
            <th>Producto</th>
            <th class="right">Cantidad</th>
            <th class="right">Precio unitario</th>
            <th class="right">Subtotal</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                <td>{{ $item->product_name }}</td>
                <td class="right">{{ number_format((float) $item->quantity, 2, '.', ',') }}</td>
                <td class="right">{{ formatMoney($item->unit_price, $country) }}</td>
                <td class="right"><strong>{{ formatMoney($item->total, $country) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="4">Sin artículos</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <section class="totals">
        <div class="payments">
            <strong>Método de pago</strong>
            @forelse ($payments as $payment)
                <div style="padding: 6px 0;">
                    <div class="payment-row">
                        <span>{{ $paymentLabels[$payment->method] ?? $payment->method }}</span>
                        <strong>{{ formatMoney($payment->amount, $country) }}</strong>
                    </div>
                    @foreach ($paymentDetailRows($payment) as $label => $value)
                        <div class="payment-row small muted">
                            <span>{{ $label }}</span>
                            <span>{{ $value }}</span>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="small muted">Sin pagos registrados</p>
            @endforelse
        </div>

        <div class="total-box">
            @if ((float) ($sale->discount_amount ?? 0) > 0)
                <div class="payment-row small muted">
                    <span>Subtotal</span>
                    <strong>{{ formatMoney($sale->subtotal_before_discount ?? $sale->total, $country) }}</strong>
                </div>
                <div class="payment-row small muted">
                    <span>Descuento</span>
                    <strong>-{{ formatMoney($sale->discount_amount, $country) }}</strong>
                </div>
                @if ($sale->discount_reason)
                    <div class="small muted" style="margin-top: 6px;">Motivo descuento: {{ $sale->discount_reason }}</div>
                @endif
            @endif
            <div class="small muted" style="margin-top: 8px;">Total</div>
            <div class="total">{{ formatMoney($sale->total, $country) }}</div>
        </div>
    </section>

    <footer class="footer">
        Gracias por su compra
    </footer>
</main>

<script>
    window.addEventListener('load', function () {
        window.setTimeout(function () {
            window.print();
        }, 300);
    });
</script>
</body>
</html>
