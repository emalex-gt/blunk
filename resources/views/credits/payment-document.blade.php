<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ format_credit_payment_number($payment) }} - Recibo de abono</title>
    <style>@page { size: Letter; margin: 12mm; }</style>
    @include('print.partials.document-styles')
    <style>.payment-totals { width: 360px; margin-left: auto; }</style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.close()">Cerrar</button>
    @include('print.partials.pdf-download-button', ['target' => '.document-page', 'filename' => 'abono-'.format_credit_payment_number($payment).'.pdf', 'kind' => 'document', 'paper' => 'letter', 'marginMm' => 12])
    <button type="button" class="primary" onclick="window.print()">Imprimir</button>
</div>
<main class="document-page">
    <header class="document-header">
        <div class="document-brand">@if($logoUrl)<img class="document-logo" src="{{ $logoUrl }}" alt="Logo">@endif<div class="document-company"><h1>{{ $business->name }}</h1>@if($branch)<div class="muted">{{ $branch->name }}</div>@endif</div></div>
        <div class="document-title"><h1>RECIBO DE ABONO</h1><strong>{{ format_credit_payment_number($payment) }}</strong><div class="muted">{{ $payment->created_at?->format('d/m/Y H:i') }}</div></div>
    </header>
    <section class="document-info"><div><span class="label">Cliente</span><div>{{ $payment->customer->name }}</div><span class="label">NIT</span><div>{{ $payment->customer->doc_number }}</div></div><div><span class="label">Método</span><div>{{ $payment->payment_method }}</div>@if($payment->reference)<span class="label">Referencia</span><div>{{ $payment->reference }}</div>@endif</div></section>
    <table class="payment-totals"><tr><td>Saldo anterior</td><td class="right">{{ formatMoney($previousBalance, $business->country) }}</td></tr><tr><td><strong>Abono recibido</strong></td><td class="right"><strong>{{ formatMoney($payment->amount, $business->country) }}</strong></td></tr><tr><td><strong>Nuevo saldo</strong></td><td class="right total">{{ formatMoney($newBalance, $business->country) }}</td></tr></table>
    @if($payment->notes)<section class="document-box"><strong>Notas:</strong> {{ $payment->notes }}</section>@endif
    <p class="muted">Registrado por {{ $payment->createdBy?->name ?: '-' }}</p>
</main>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
