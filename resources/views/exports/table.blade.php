<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0f172a; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { margin-bottom: 12px; color: #475569; }
        .summary { margin: 10px 0 14px; }
        .summary span { display: inline-block; margin-right: 14px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; }
        th { background: #f1f5f9; text-align: left; font-weight: bold; }
        .filters { margin-top: 8px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        <div>{{ $businessName }}</div>
        <div>Sucursal: {{ $branchName }}</div>
        <div>Generado: {{ $generatedAt }}</div>
        @if (! empty($filters))
            <div class="filters">Filtros: {{ $filters }}</div>
        @endif
    </div>

    @if (! empty($summary))
        <div class="summary">
            @foreach ($summary as $item)
                <span>{{ $item['label'] }}: {{ $item['value'] }}</span>
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}">Sin datos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
