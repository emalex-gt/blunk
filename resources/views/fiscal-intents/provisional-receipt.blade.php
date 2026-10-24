<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Comprobante provisional</title></head>
<body>
    <h1>COMPROBANTE PROVISIONAL — NO ES FACTURA NI DOCUMENTO TRIBUTARIO FEL</h1>
    <p><strong>Referencia interna:</strong> {{ $intent->internal_reference }}</p>
    <p><strong>Estado fiscal:</strong> {{ $intent->status }}</p>
    <p><strong>Empresa:</strong> {{ $intent->business?->name }}</p>
    <p><strong>Sucursal:</strong> {{ $intent->branch?->name ?? '-' }}</p>
    <p><strong>Emitido:</strong> {{ $intent->created_at?->format('Y-m-d H:i') }}</p>
    <p>Este comprobante acredita una solicitud en verificación y no sustituye una factura ni documento tributario FEL.</p>
</body>
</html>
