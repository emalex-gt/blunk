<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante provisional</title>
    @include('print.partials.ticket-styles')
    <style>
        .provisional-warning {
            border: 2px solid #000;
            font-size: 13px;
            font-weight: 700;
            margin: 6px 0;
            padding: 6px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" onclick="window.close()">Cerrar</button>
        @include('print.partials.pdf-download-button', ['target' => '.ticket', 'filename' => 'comprobante-provisional-'.$intent->id.'.pdf', 'kind' => 'ticket', 'widthMm' => 80, 'marginMm' => 4])
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>
    <main class="ticket">
        <div class="provisional-warning">COMPROBANTE PROVISIONAL<br>NO ES FACTURA NI DOCUMENTO TRIBUTARIO FEL</div>
        <div class="line"></div>
        <p><strong>Referencia interna:</strong> {{ $intent->internal_reference }}</p>
        <p><strong>Estado fiscal:</strong> {{ $intent->status }}</p>
        <p><strong>Empresa:</strong> {{ $intent->business?->name }}</p>
        <p><strong>Sucursal:</strong> {{ $intent->branch?->name ?? '-' }}</p>
        <p><strong>Emitido:</strong> {{ $intent->created_at?->format('Y-m-d H:i') }}</p>
        <div class="line"></div>
        <p>Este comprobante acredita una solicitud en verificación y no sustituye una factura ni documento tributario FEL.</p>
    </main>
</body>
</html>
