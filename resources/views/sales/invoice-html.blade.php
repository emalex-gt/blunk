<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ format_sale_number($sale) }}</title>
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, sans-serif; }
        .toolbar { padding: 12px; background: #fff; border-bottom: 1px solid #e2e8f0; }
        .toolbar button { border: 0; border-radius: 10px; background: #4f46e5; color: #fff; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .document { background: #fff; margin: 16px auto; max-width: 960px; padding: 16px; box-shadow: 0 8px 30px rgba(15, 23, 42, .08); }
        @media print {
            .toolbar { display: none; }
            body, .document { margin: 0; background: #fff; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Imprimir factura</button>
    </div>
    <main class="document">
        {!! $html !!}
    </main>
    <script>
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
