<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ format_credit_payment_number($payment) }} - Recibo de abono</title>
    @include('print.partials.ticket-styles')
</head>
<body>
    <main class="ticket">
    <div class="center">
        @if($logoUrl)<img class="logo" src="{{ $logoUrl }}" alt="Logo">@endif
        <div><strong>{{ $business->name }}</strong></div>
        @if($branch)<div class="muted">{{ $branch->name }}</div>@endif
        <h2>RECIBO DE ABONO</h2>
        <div><strong>{{ format_credit_payment_number($payment) }}</strong></div>
        <div class="muted">{{ $payment->created_at?->format('d/m/Y H:i') }}</div>
    </div>
    <div class="line"></div>
    <div><strong>Cliente:</strong> {{ $payment->customer->name }}</div>
    <div><strong>NIT:</strong> {{ $payment->customer->doc_number }}</div>
    <div><strong>Método:</strong> {{ $payment->payment_method }}</div>
    @if($payment->reference)<div><strong>Referencia:</strong> {{ $payment->reference }}</div>@endif
    <div class="line"></div>
    <table>
        <tr><td>Saldo anterior</td><td class="right">{{ formatMoney($previousBalance, $business->country) }}</td></tr>
        <tr><td><strong>Abono recibido</strong></td><td class="right"><strong>{{ formatMoney($payment->amount, $business->country) }}</strong></td></tr>
        <tr><td>Nuevo saldo</td><td class="right">{{ formatMoney($newBalance, $business->country) }}</td></tr>
    </table>
    <div class="line"></div>
    <div class="center muted">Registrado por {{ $payment->createdBy?->name ?: '-' }}</div>
    </main>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
