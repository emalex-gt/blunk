<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Correctivo de cierre #{{ $correction->id }}</title>
    <style>
        body { color: #111827; font-family: Arial, sans-serif; font-size: 12px; margin: 28px; }
        .page { max-width: 720px; margin: 0 auto; } .no-print { margin-bottom: 16px; text-align: center; }
        .print-button { border: 1px solid #cbd5e1; background: white; border-radius: 10px; padding: 8px 14px; cursor: pointer; }
        .center { text-align: center; } .muted { color: #64748b; } .amount { font-size: 20px; font-weight: 700; }
        .card { border: 1px solid #cbd5e1; margin-top: 18px; padding: 14px; }
        table { border-collapse: collapse; width: 100%; } td { padding: 6px 0; vertical-align: top; }
        td:first-child { color: #475569; width: 42%; } @media print { body { margin: 12mm; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print">
        @include('print.partials.pdf-download-button', ['target' => '.page', 'filename' => 'correctivo-cierre-'.$correction->id.'.pdf', 'kind' => 'document', 'paper' => ($company['country'] ?? 'GT') === 'AR' ? 'A4' : 'Letter', 'marginMm' => 12, 'class' => 'print-button'])
        <button class="print-button" onclick="window.print()">Imprimir correctivo</button>
    </div>
    <main class="page">
    <div class="center">
        <strong>{{ $company['name'] }}</strong><br>
        @if($company['tax_id'])<span class="muted">{{ $company['tax_id'] }}</span><br>@endif
        @if($company['address'])<span class="muted">{{ $company['address'] }}</span><br>@endif
        <h2>Comprobante correctivo de cierre</h2>
        <div class="muted">Correctivo #{{ $correction->id }}</div>
    </div>

    <div class="card">
        <table>
            <tr><td>Fecha</td><td>{{ optional($correction->occurred_at)->timezone($timezone)->format('d/m/Y H:i') ?? 'No disponible' }}</td></tr>
            <tr><td>Venta corregida</td><td>#{{ $correction->sale?->business_number ?? 'No disponible' }}</td></tr>
            <tr><td>Cierre original</td><td>#{{ $correction->original_cash_register_session_id ?? 'No determinado' }}</td></tr>
            <tr><td>Turno de corrección</td><td>{{ $correction->correction_cash_register_session_id ? '#'.$correction->correction_cash_register_session_id : 'Atribución histórica no determinada' }}</td></tr>
            <tr><td>Medio de pago</td><td>{{ $correction->payment_method ?: 'No determinado' }}</td></tr>
            <tr><td>Responsable</td><td>{{ $correction->responsibleUser?->name ?? 'No disponible' }}</td></tr>
            @if($correction->reason)<tr><td>Motivo</td><td>{{ $correction->reason }}</td></tr>@endif
        </table>
        <p class="amount">Corrección comercial: {{ formatMoney((float) $correction->amount, $company['country'] ?? null) }}</p>
        <p class="muted">Este comprobante no modifica el cierre original ni vuelve a registrar movimientos de caja.</p>
    </div>
    </main>
</body>
</html>
