<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura FEL #{{ $sale->id }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
        }
        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            max-width: 900px;
            margin: 16px auto;
        }
        button {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #fff;
            color: #334155;
            cursor: pointer;
            font-weight: 700;
            padding: 9px 14px;
        }
        .invoice {
            width: 100%;
            max-width: 900px;
            margin: 0 auto 24px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            padding: 28px;
        }
        .header {
            display: grid;
            grid-template-columns: 1fr 310px;
            gap: 24px;
            align-items: start;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 18px;
        }
        .brand { display: flex; gap: 14px; align-items: flex-start; }
        .logo { max-width: 90px; max-height: 80px; object-fit: contain; }
        h1, h2, p { margin: 0; }
        h1 { font-size: 21px; }
        .muted { color: #64748b; }
        .fel-box {
            border: 2px solid #312e81;
            border-radius: 14px;
            padding: 14px;
            text-align: center;
        }
        .fel-title {
            color: #312e81;
            font-size: 16px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 18px;
        }
        .panel {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
        }
        .label {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }
        th {
            background: #f1f5f9;
            color: #475569;
            font-size: 11px;
            letter-spacing: .04em;
            padding: 10px 8px;
            text-align: left;
            text-transform: uppercase;
        }
        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 8px;
            vertical-align: top;
        }
        .right { text-align: right; white-space: nowrap; }
        .totals {
            display: flex;
            justify-content: flex-end;
            margin-top: 16px;
        }
        .totals table { width: 330px; margin-top: 0; }
        .total-row td {
            border-top: 2px solid #0f172a;
            font-size: 16px;
            font-weight: 800;
        }
        .qr {
            align-items: center;
            border: 1px dashed #94a3b8;
            border-radius: 12px;
            color: #64748b;
            display: flex;
            height: 96px;
            justify-content: center;
            margin-top: 18px;
            text-align: center;
        }
        .footer {
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            margin-top: 18px;
            padding-top: 12px;
            text-align: center;
        }
        @media print {
            body { background: #fff; }
            .actions { display: none; }
            .invoice {
                border: none;
                border-radius: 0;
                box-shadow: none;
                margin: 0;
                max-width: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Imprimir</button>
        <button type="button" onclick="window.close()">Cerrar</button>
    </div>

    <main class="invoice">
        <section class="header">
            <div class="brand">
                @if(! empty($company['logo_url']))
                    <img class="logo" src="{{ $company['logo_url'] }}" alt="Logo">
                @endif
                <div>
                    <h1>{{ $company['name'] }}</h1>
                    <p class="muted">NIT emisor: {{ $company['tax_id'] ?: '-' }}</p>
                    <p class="muted">{{ $company['address'] ?: 'Ciudad' }}</p>
                    @if(! empty($company['phone']))
                        <p class="muted">Tel: {{ $company['phone'] }}</p>
                    @endif
                </div>
            </div>

            <div class="fel-box">
                <div class="fel-title">Documento Tributario Electrónico</div>
                <div class="label" style="margin-top: 8px;">Factura FEL</div>
                <div>Venta #{{ $sale->id }}</div>
            </div>
        </section>

        <section class="grid">
            <div class="panel">
                <div class="label">Cliente</div>
                <div><strong>{{ $sale->customer?->name ?: 'Consumidor Final' }}</strong></div>
                <div>NIT: {{ $sale->customer?->doc_number ?: 'CF' }}</div>
                @if($sale->customer?->address)
                    <div>Dirección: {{ $sale->customer->address }}</div>
                @endif
            </div>
            <div class="panel">
                <div class="label">Certificación FEL</div>
                <div>UUID: {{ $sale->fel_uuid }}</div>
                <div>Serie: {{ $sale->fel_series ?: '-' }}</div>
                <div>Número: {{ $sale->fel_number ?: '-' }}</div>
                <div>Fecha certificación: {{ $certifiedAtLocal ?: '-' }}</div>
                <div>Fecha emisión: {{ $createdAtLocal ?: '-' }}</div>
            </div>
        </section>

        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="right">Cantidad</th>
                    <th class="right">Precio unitario</th>
                    <th class="right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item->product_name }}</td>
                        <td class="right">{{ number_format((float) $item->quantity, 2) }}</td>
                        <td class="right">Q{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="right">Q{{ number_format((float) $item->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td class="right">Q{{ number_format($subtotal, 2) }}</td>
                </tr>
                <tr>
                    <td>IVA</td>
                    <td class="right">Q{{ number_format($iva, 2) }}</td>
                </tr>
                <tr class="total-row">
                    <td>Total</td>
                    <td class="right">Q{{ number_format($total, 2) }}</td>
                </tr>
            </table>
        </div>

        <section class="qr">
            QR FEL<br>
            {{ $sale->fel_uuid }}
        </section>

        <div class="footer">
            Documento certificado electrónicamente. Gracias por su compra.
        </div>
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
