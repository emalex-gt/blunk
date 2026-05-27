<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket {{ format_sale_number($sale) }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 3mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f1f5f9;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
        }

        .toolbar {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin: 12px auto;
            width: 80mm;
        }

        button {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: white;
            color: #334155;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 10px;
        }

        button.primary {
            border-color: #4f46e5;
            background: #4f46e5;
            color: white;
        }

        .ticket {
            width: 80mm;
            margin: 0 auto 20px;
            background: white;
            padding: 4mm;
        }

        .center {
            text-align: center;
        }

        .logo {
            display: block;
            height: 38px;
            margin: 0 auto 6px;
            max-width: 55mm;
            object-fit: contain;
        }

        h1,
        p {
            margin: 0;
        }

        h1 {
            font-size: 15px;
            line-height: 1.2;
        }

        .muted {
            color: #4b5563;
        }

        .line {
            border-top: 1px dashed #9ca3af;
            margin: 8px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .item {
            margin-bottom: 6px;
        }

        .item-name {
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .right {
            text-align: right;
        }

        .total {
            font-size: 18px;
            font-weight: 800;
        }

        .cancelled-badge {
            border-bottom: 1px dashed #111827;
            border-top: 1px dashed #111827;
            font-size: 14px;
            font-weight: 900;
            margin: 8px 0;
            padding: 6px 0;
            text-align: center;
        }

        .cancelled-info {
            font-size: 11px;
            margin-bottom: 8px;
            overflow-wrap: anywhere;
        }

        @media print {
            body {
                background: white;
                color: black;
            }

            .no-print {
                display: none !important;
            }

            .ticket {
                margin: 0;
                padding: 0;
                width: 100%;
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

<main class="ticket">
    <header class="center">
        @if (! empty($company['logo_url']))
            <img src="{{ $company['logo_url'] }}" alt="Logo" class="logo">
        @endif
        <h1>{{ $company['name'] ?? $business?->name ?? 'Empresa' }}</h1>
        @if (! empty($company['tax_id']))
            <p>NIT/CUIT: {{ $company['tax_id'] }}</p>
        @endif
        @if (! empty($company['address']))
            <p>{{ $company['address'] }}</p>
        @endif
        @if (! empty($company['phone']))
            <p>Tel: {{ $company['phone'] }}</p>
        @endif
    </header>

    <div class="line"></div>

    @if ($sale->status === 'cancelled')
        <section>
            <div class="cancelled-badge">*** VENTA ANULADA ***</div>
            <div class="cancelled-info">
                @if ($sale->cancellation_reason)
                    <div><strong>Motivo:</strong> {{ $sale->cancellation_reason }}</div>
                @endif
                @if (! empty($cancelledAtLocal))
                    <div><strong>Fecha de anulación:</strong> {{ $cancelledAtLocal }}</div>
                @endif
                @if ($sale->cancelledBy?->name)
                    <div><strong>Anulada por:</strong> {{ $sale->cancelledBy->name }}</div>
                @endif
            </div>
        </section>

        <div class="line"></div>
    @endif

    <section>
        <div class="row">
            <strong>Comprobante</strong>
            <span>{{ format_sale_number($sale) }}</span>
        </div>
        <div class="row">
            <span>Fecha</span>
            <span>{{ $createdAtLocal ?? '-' }}</span>
        </div>
        <div class="row">
            <span>Cliente</span>
            <span class="right">{{ $sale->customer?->name ?? 'Consumidor Final' }}</span>
        </div>
        @if ($sale->customer?->doc_number)
            <div class="row">
                <span>{{ $sale->customer?->doc_type ?? 'Doc.' }}</span>
                <span>{{ $sale->customer?->doc_number }}</span>
            </div>
        @endif
    </section>

    <div class="line"></div>

    <section>
        @forelse ($items as $item)
            <div class="item">
                <div class="item-name">{{ $item->product_name }}</div>
                <div class="row">
                    <span>{{ number_format((float) $item->quantity, 2, '.', ',') }} x {{ formatMoney($item->unit_price, $country) }}</span>
                    <strong>{{ formatMoney($item->total, $country) }}</strong>
                </div>
            </div>
        @empty
            <p>Sin artículos</p>
        @endforelse
    </section>

    <div class="line"></div>

    <section>
        @forelse ($payments as $payment)
            <div style="margin-bottom: 5px;">
                <div class="row">
                    <span>{{ $paymentLabels[$payment->method] ?? $payment->method }}</span>
                    <strong>{{ formatMoney($payment->amount, $country) }}</strong>
                </div>
                @foreach ($paymentDetailRows($payment) as $label => $value)
                    <div class="row muted">
                        <span>{{ $label }}</span>
                        <span class="right">{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        @empty
            <p>Sin pagos registrados</p>
        @endforelse
    </section>

    <div class="line"></div>

    @if ((float) ($sale->discount_amount ?? 0) > 0)
        <section>
            <div class="row">
                <span>Subtotal</span>
                <strong>{{ formatMoney($sale->subtotal_before_discount ?? $sale->total, $country) }}</strong>
            </div>
            <div class="row">
                <span>Descuento</span>
                <strong>-{{ formatMoney($sale->discount_amount, $country) }}</strong>
            </div>
            @if ($sale->discount_reason)
                <div class="muted">Motivo descuento: {{ $sale->discount_reason }}</div>
            @endif
        </section>

        <div class="line"></div>
    @endif

    <section class="row">
        <strong>Total</strong>
        <span class="total">{{ formatMoney($sale->total, $country) }}</span>
    </section>

    <div class="line"></div>

    <footer class="center">
        <strong>Gracias por su compra</strong>
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
