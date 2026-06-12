<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ format_credit_payment_number($payment) }} - Recibo de abono</title>
    <style>
        @page { size: letter; margin: 16mm; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 0; font-size: 13px; }
        header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111827; padding-bottom: 14px; }
        .logo { max-width: 160px; max-height: 80px; object-fit: contain; } .right { text-align: right; } .muted { color: #4b5563; }
        .box { border: 1px solid #d1d5db; border-radius: 6px; padding: 12px; margin-top: 14px; }
        table { width: 360px; margin: 18px 0 0 auto; border-collapse: collapse; } td { border-bottom: 1px solid #e5e7eb; padding: 8px; }
    </style>
</head>
<body>
    <header>
        <div>@if($logoUrl)<img class="logo" src="{{ $logoUrl }}" alt="Logo">@endif<div><strong>{{ $business->name }}</strong></div>@if($branch)<div class="muted">{{ $branch->name }}</div>@endif</div>
        <div class="right"><h1>RECIBO DE ABONO</h1><strong>{{ format_credit_payment_number($payment) }}</strong><div class="muted">{{ $payment->created_at?->format('d/m/Y H:i') }}</div></div>
    </header>
    <section class="box"><div><strong>Cliente:</strong> {{ $payment->customer->name }}</div><div><strong>NIT:</strong> {{ $payment->customer->doc_number }}</div><div><strong>Método:</strong> {{ $payment->payment_method }}</div>@if($payment->reference)<div><strong>Referencia:</strong> {{ $payment->reference }}</div>@endif</section>
    <table><tr><td>Saldo anterior</td><td class="right">{{ formatMoney($previousBalance, $business->country) }}</td></tr><tr><td><strong>Abono recibido</strong></td><td class="right"><strong>{{ formatMoney($payment->amount, $business->country) }}</strong></td></tr><tr><td>Nuevo saldo</td><td class="right">{{ formatMoney($newBalance, $business->country) }}</td></tr></table>
    @if($payment->notes)<section class="box"><strong>Notas:</strong> {{ $payment->notes }}</section>@endif
    <p class="muted">Registrado por {{ $payment->createdBy?->name ?: '-' }}</p>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
