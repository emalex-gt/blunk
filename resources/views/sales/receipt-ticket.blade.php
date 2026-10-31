<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket {{ format_sale_number($sale) }}</title>
    @include('print.partials.ticket-styles')
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
    @include('print.partials.pdf-download-button', ['target' => '.ticket', 'filename' => 'venta-'.format_sale_number($sale).'.pdf', 'kind' => 'ticket', 'widthMm' => 80, 'marginMm' => 4])
    @include('print.partials.delivery-print-toggle', ['delivery' => $delivery])
    <button type="button" class="primary" onclick="window.print()">Imprimir</button>
</div>

<main class="ticket">
    <header class="center">
        <h1>{{ $company['name'] ?? $business?->name ?? 'Empresa' }}</h1>
        @if (! empty($company['address']))
            <p>{{ $company['address'] }}</p>
        @endif
        @if (! empty($company['phone']))
            <p>{{ $company['phone'] }}</p>
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
            <span class="right">{{ $customerDisplayName ?? 'Consumidor final' }}</span>
        </div>
        @if (filled($delivery['address'] ?? null))
            <div class="row">
                <span>Dirección</span>
                <span class="right">{{ $delivery['address'] }}</span>
            </div>
        @endif
        <div class="row">
            <span>Le atendió</span>
            <span class="right">{{ $attendedBy }}</span>
        </div>
    </section>

    <div class="line"></div>

    @include('print.partials.delivery-details', ['delivery' => $delivery])

    @if (collect([$delivery['address_reference'] ?? null, $delivery['phone'] ?? null])->contains(fn ($value) => filled(trim((string) $value))))
        <div class="line delivery-details" data-delivery-details></div>
    @endif

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
