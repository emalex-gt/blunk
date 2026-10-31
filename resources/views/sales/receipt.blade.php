<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante {{ format_sale_number($sale) }}</title>
    <style>@page { size: {{ $paperSize }}; margin: 12mm; }</style>
    @include('print.partials.document-styles')
    <style>
        .payment-row { display: flex; justify-content: space-between; gap: 16px; padding: 4px 0; }
        .total-box { text-align: right; }
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
    @include('print.partials.pdf-download-button', ['target' => '.document-page', 'filename' => 'venta-'.format_sale_number($sale).'.pdf', 'kind' => 'document', 'paper' => $paperSize, 'marginMm' => 12])
    @include('print.partials.delivery-print-toggle', ['delivery' => $delivery])
    <button type="button" class="primary" onclick="window.print()">Imprimir</button>
</div>

<main class="document-page">
    <header class="document-header">
        <div class="document-brand">
            @if (! empty($company['logo_url']))
                <img src="{{ $company['logo_url'] }}" alt="Logo" class="logo">
            @endif
            <div class="document-company">
                <h1>{{ $company['name'] ?? $business?->name ?? 'Empresa' }}</h1>
                @if (! empty($company['address']))
                    <p class="small">{{ $company['address'] }}</p>
                @endif
                @if (! empty($company['phone']))
                    <p class="small">{{ $company['phone'] }}</p>
                @endif
            </div>
        </div>

        <div class="document-title">
            <h2>Comprobante de venta</h2>
            <p>Venta {{ format_sale_number($sale) }}</p>
            <p class="small muted">Fecha: {{ $createdAtLocal ?? '-' }}</p>
        </div>
    </header>

    @if ($sale->status === 'cancelled')
        <section class="cancelled-badge">
            VENTA ANULADA
        </section>
        <section class="document-box cancelled-info">
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

    <section class="document-info">
        <div>
            <strong>Cliente</strong>
            <p>{{ $customerDisplayName ?? 'Consumidor final' }}</p>
            @if (filled($delivery['address'] ?? null))
                <p><strong>Dirección:</strong> {{ $delivery['address'] }}</p>
            @endif
        </div>
        <div class="right">
            <strong>Le atendió</strong>
            <p>{{ $attendedBy }}</p>
        </div>
    </section>

    @include('print.partials.delivery-details', ['delivery' => $delivery])

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

    <section class="document-summary">
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
