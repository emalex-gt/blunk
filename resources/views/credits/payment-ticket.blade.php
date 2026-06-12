<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ format_credit_payment_number($payment) }} - Recibo de abono</title>
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        body { font-family: Arial, sans-serif; margin: 0; color: #111827; font-size: 12px; }
        .center { text-align: center; } .right { text-align: right; } .muted { color: #4b5563; }
        .logo { max-width: 52mm; max-height: 24mm; object-fit: contain; margin-bottom: 6px; }
        .line { border-top: 1px dashed #9ca3af; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; } td { padding: 3px 0; }
    </style>
</head>
<body>
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
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
