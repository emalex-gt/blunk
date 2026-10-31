<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Constancia provisional de operación</title>
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
        <div class="provisional-warning">CONSTANCIA PROVISIONAL DE OPERACIÓN<br>NO ES FACTURA NI DOCUMENTO TRIBUTARIO</div>
        <div class="line"></div>
        <p><strong>Referencia interna:</strong> {{ $intent->internal_reference }}</p>
        <p><strong>Estado fiscal:</strong> {{ $intent->status }}</p>
        <p><strong>Empresa:</strong> {{ $intent->business?->name }}</p>
        <p><strong>Sucursal:</strong> {{ $intent->branch?->name ?? '-' }}</p>
        <p><strong>Emitido:</strong> {{ $intent->created_at?->format('Y-m-d H:i') }}</p>
        @php($sale = data_get($operation, 'sale', []))
        @php($items = data_get($operation, 'items', []))
        @if(filled(data_get($sale, 'customer_name')))
            <p><strong>Cliente:</strong> {{ data_get($sale, 'customer_name') }}</p>
        @endif
        @if(! empty($items))
            <div class="line"></div>
            <p><strong>Detalle de operación</strong></p>
            @foreach($items as $item)
                <p>{{ data_get($item, 'quantity', 0) }} × {{ data_get($item, 'product_name', 'Producto') }} — {{ formatMoney(data_get($item, 'total', 0), $intent->business?->country) }}</p>
            @endforeach
        @endif
        @if(data_get($sale, 'total') !== null)
            <p><strong>Importe:</strong> {{ formatMoney(data_get($sale, 'total'), $intent->business?->country) }}</p>
        @endif
        <div class="line"></div>
        <p>Este comprobante acredita una solicitud en verificación y no sustituye una factura ni documento tributario FEL.</p>
    </main>
</body>
</html>
