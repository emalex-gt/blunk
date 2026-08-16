<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Traslado #{{ $transfer->id }}</title>
    <style>
        @page { size: letter portrait; margin: 0.55in; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; line-height: 1.35; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .logo, .company, .title { display: table-cell; vertical-align: top; }
        .logo { width: 88px; }
        .logo img { max-width: 76px; max-height: 76px; object-fit: contain; }
        .company h1 { margin: 0 0 4px; font-size: 18px; }
        .company div { color: #475569; }
        .title { width: 190px; text-align: right; }
        .title h2 { margin: 0 0 6px; font-size: 18px; text-transform: uppercase; }
        .title .number { font-size: 13px; font-weight: bold; color: #4338ca; }
        .grid { display: table; width: 100%; margin-bottom: 14px; border: 1px solid #cbd5e1; }
        .row { display: table-row; }
        .cell { display: table-cell; width: 50%; padding: 7px 9px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .row:last-child .cell { border-bottom: 0; }
        .label { display: block; color: #64748b; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .value { display: block; margin-top: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 7px; vertical-align: top; }
        th { background: #f1f5f9; color: #334155; font-size: 9px; text-align: left; text-transform: uppercase; }
        .right { text-align: right; }
        .summary { margin-left: auto; margin-top: 12px; width: 260px; }
        .summary td { font-size: 12px; font-weight: bold; }
        .signatures { display: table; width: 100%; margin-top: 46px; }
        .signature { display: table-cell; width: 50%; text-align: center; color: #475569; }
        .line { margin: 0 auto 6px; width: 210px; border-top: 1px solid #475569; }
        .footer { position: fixed; bottom: -0.25in; left: 0; right: 0; text-align: center; font-size: 9px; color: #64748b; }
    </style>
</head>
<body>
@php
    $company ??= [
        'logo_url' => $transfer->fromBranch?->logo_url ?: ($business?->logo_url ?: $tenantSetting?->company_logo_url),
        'name' => $business?->name ?: $tenantSetting?->company_name,
        'address' => $transfer->fromBranch?->address ?: $tenantSetting?->company_address,
        'phone' => $transfer->fromBranch?->phone ?: $tenantSetting?->company_phone,
    ];
    $totalUnits = $transfer->lines->sum(fn ($line) => (int) $line->quantity);
@endphp
    <div class="header">
        <div class="logo">
            @if (! empty($company['logo_url']))
                <img src="{{ $company['logo_url'] }}" alt="Logo">
            @endif
        </div>
        <div class="company">
            <h1>{{ $company['name'] ?: 'Empresa' }}</h1>
            @if (! empty($company['address']))<div>{{ $company['address'] }}</div>@endif
            @if (! empty($company['phone']))<div>{{ $company['phone'] }}</div>@endif
        </div>
        <div class="title">
            <h2>Traslado de inventario</h2>
            <div class="number">#{{ $transfer->id }}</div>
        </div>
    </div>

    <div class="grid">
        <div class="row">
            <div class="cell"><span class="label">Fecha</span><span class="value">{{ $transfer->created_at?->timezone($timezone)->format('d/m/Y H:i') }}</span></div>
            <div class="cell"><span class="label">Estado</span><span class="value">{{ $transfer->status === 'completed' ? 'Completado' : $transfer->status }}</span></div>
        </div>
        <div class="row">
            <div class="cell"><span class="label">Sucursal origen</span><span class="value">{{ $transfer->fromBranch?->name ?? '-' }}</span></div>
            <div class="cell"><span class="label">Sucursal destino</span><span class="value">{{ $transfer->toBranch?->name ?? '-' }}</span></div>
        </div>
        <div class="row">
            <div class="cell"><span class="label">Usuario que registró</span><span class="value">{{ $transfer->createdBy?->name ?? '-' }}</span></div>
            <div class="cell"><span class="label">Nota / motivo</span><span class="value">{{ $transfer->notes ?: '-' }}</span></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 18%;">Código</th>
                <th>Producto</th>
                <th class="right" style="width: 16%;">Cantidad trasladada</th>
                <th style="width: 14%;">Unidad</th>
                <th style="width: 20%;">Observación</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transfer->lines as $line)
                <tr>
                    <td>{{ $line->product?->code ?: ($line->product?->barcode ?: '-') }}</td>
                    <td>{{ $line->product?->name ?? '-' }}</td>
                    <td class="right">{{ number_format((int) $line->quantity) }}</td>
                    <td>Unidad</td>
                    <td>-</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr><td>Total productos</td><td class="right">{{ $transfer->lines->count() }}</td></tr>
        <tr><td>Total unidades</td><td class="right">{{ number_format($totalUnits) }}</td></tr>
    </table>

    <div class="signatures">
        <div class="signature"><div class="line"></div>Entrega</div>
        <div class="signature"><div class="line"></div>Recibe</div>
    </div>

    <div class="footer">Documento interno generado por Kodbli/BlunkStock</div>
</body>
</html>
