<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Traslado de inventario #{{ $transfer->id }}</title>
    <style>@page { size: Letter; margin: 12mm; }</style>
    @include('print.partials.document-styles')
    <style>
        .transfer-signatures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 60px; margin-top: 50px; break-inside: avoid; page-break-inside: avoid; }
        .transfer-signature { text-align: center; }
        .transfer-signature-line { border-top: 1px solid #0d62ab; margin-bottom: 6px; }
        .document-footer { margin-top: 28px; text-align: center; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
@php
    $company ??= \App\Support\DocumentCompanyHeader::make($business, $transfer->fromBranch, $tenantSetting);
    $totalUnits = $transfer->lines->sum(fn ($line) => (int) $line->quantity);
@endphp

<div class="toolbar no-print">
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="primary" onclick="window.print()">Imprimir</button>
</div>

<main class="document-page">
    <header class="document-header">
        <div class="document-brand">
            @if (! empty($company['logo_url']))
                <img src="{{ $company['logo_url'] }}" alt="Logo de {{ $company['name'] }}" class="document-logo">
            @endif
            <div class="document-company">
                <h1>{{ $company['name'] ?: 'Empresa' }}</h1>
                @if (! empty($company['address']))<div>{{ $company['address'] }}</div>@endif
                @if (! empty($company['phone']))<div>Tel. {{ $company['phone'] }}</div>@endif
            </div>
        </div>
        <div class="document-title">
            <h1>TRASLADO DE INVENTARIO</h1>
            <strong>#{{ $transfer->id }}</strong>
        </div>
    </header>

    <section class="document-info">
        <div>
            <span class="label">Fecha</span>
            <div>{{ optional($transfer->created_at)->timezone($timezone)->format('d/m/Y H:i') }}</div>
        </div>
        <div>
            <span class="label">Estado</span>
            <div>{{ $transfer->status === 'completed' ? 'Completado' : $transfer->status }}</div>
        </div>
        <div>
            <span class="label">Origen</span>
            <div>{{ $transfer->fromBranch?->name ?: '-' }}</div>
        </div>
        <div>
            <span class="label">Destino</span>
            <div>{{ $transfer->toBranch?->name ?: '-' }}</div>
        </div>
        <div>
            <span class="label">Creado por</span>
            <div>{{ $transfer->createdBy?->name ?: '-' }}</div>
        </div>
        @if ($transfer->notes)
            <div>
                <span class="label">Nota / motivo</span>
                <div>{{ $transfer->notes }}</div>
            </div>
        @endif
    </section>

    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th class="right">Cantidad</th>
                <th>Unidad</th>
                <th>Observación</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transfer->lines as $line)
                <tr>
                    <td>{{ $line->product?->code ?: $line->product?->barcode ?: '-' }}</td>
                    <td>{{ $line->product?->name ?: '-' }}</td>
                    <td class="right">{{ number_format((float) $line->quantity, 2) }}</td>
                    <td>Unidad</td>
                    <td>-</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="document-summary">
        <div></div>
        <table>
            <tr><td>Total productos</td><td class="right">{{ $transfer->lines->count() }}</td></tr>
            <tr><td><strong>Total unidades</strong></td><td class="right total">{{ number_format($totalUnits, 0) }}</td></tr>
        </table>
    </section>

    <section class="transfer-signatures">
        <div class="transfer-signature"><div class="transfer-signature-line"></div>Entrega</div>
        <div class="transfer-signature"><div class="transfer-signature-line"></div>Recibe</div>
    </section>

    <footer class="document-footer">Documento interno generado por Blunk</footer>
</main>

<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 300);
});
</script>
</body>
</html>
