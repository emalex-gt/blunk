<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cierre de caja #{{ $session->id }}</title>
    <style>
        @page {
            size: {{ $receiptFormat === 'ticket' ? '80mm auto' : $paperSize }};
            margin: {{ $receiptFormat === 'ticket' ? '3mm' : '12mm' }};
        }

        body {
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
            font-family: Arial, sans-serif;
            font-size: {{ $receiptFormat === 'ticket' ? '12px' : '14px' }};
        }

        .page {
            width: {{ $receiptFormat === 'ticket' ? '74mm' : '100%' }};
            max-width: {{ $receiptFormat === 'ticket' ? '74mm' : '900px' }};
            margin: 0 auto;
            background: white;
            padding: {{ $receiptFormat === 'ticket' ? '8px' : '28px' }};
        }

        .no-print { margin: 12px auto; max-width: 900px; text-align: center; }
        .print-button { border: 1px solid #cbd5e1; background: white; border-radius: 10px; padding: 8px 14px; cursor: pointer; }
        .center { text-align: center; }
        .logo { max-height: {{ $receiptFormat === 'ticket' ? '44px' : '70px' }}; max-width: 180px; object-fit: contain; }
        h1 { margin: 14px 0 10px; font-size: {{ $receiptFormat === 'ticket' ? '16px' : '24px' }}; }
        .muted { color: #64748b; }
        .line { border-top: 1px solid #e2e8f0; margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: {{ $receiptFormat === 'ticket' ? '4px 0' : '9px 8px' }}; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        th { color: #64748b; font-size: {{ $receiptFormat === 'ticket' ? '10px' : '11px' }}; text-transform: uppercase; }
        .right { text-align: right; white-space: nowrap; }
        .total { font-size: {{ $receiptFormat === 'ticket' ? '14px' : '18px' }}; font-weight: bold; }
        .negative { color: #dc2626; }
        .positive { color: #047857; }

        @media print {
            body { background: white; }
            .page { box-shadow: none; padding: 0; }
            .no-print { display: none; }
            .negative, .positive { color: #000; }
        }
    </style>
</head>
<body>
    @php
        $isSnapshot = ($closingContract['source'] ?? null) === 'snapshot';
        $frozenSession = $isSnapshot ? ($closingContract['session'] ?? []) : [];
        $sessionValue = static fn (string $key, mixed $fallback = null): mixed => $isSnapshot && array_key_exists($key, $frozenSession)
            ? $frozenSession[$key]
            : $fallback;
    @endphp
    <div class="no-print">
        @include('print.partials.pdf-download-button', ['target' => '.page', 'filename' => 'cierre-caja-'.$session->id.'.pdf', 'kind' => $receiptFormat === 'ticket' ? 'ticket' : 'document', 'paper' => $receiptFormat === 'ticket' ? null : $paperSize, 'widthMm' => $receiptFormat === 'ticket' ? 80 : null, 'marginMm' => $receiptFormat === 'ticket' ? 3 : 12, 'class' => 'print-button'])
        <button class="print-button" onclick="window.print()">Imprimir cierre</button>
    </div>

    <main class="page">
        <header class="center">
            @if (! empty($company['logo_url']))
                <img class="logo" src="{{ $company['logo_url'] }}" alt="{{ $company['name'] }}">
            @endif
            <h1>Cierre de caja</h1>
            <div><strong>{{ $company['name'] }}</strong></div>
            @if (! empty($company['tax_id']))
                <div class="muted">{{ $company['tax_id'] }}</div>
            @endif
            @if (! empty($company['address']))
                <div class="muted">{{ $company['address'] }}</div>
            @endif
            @if (! empty($company['phone']))
                <div class="muted">{{ $company['phone'] }}</div>
            @endif
        </header>

        <div class="line"></div>
<div class="center total">
    @if ($receiptFormat === 'ticket')
        <div style="font-size:18px;">{{ formatMoney($salesSummary['total'], $business?->country) }}</div>
        <div class="muted" style="font-size:11px; font-weight:normal;">TOTAL VENDIDO DEL TURNO</div>
    @else
        <div>TOTAL VENDIDO DEL TURNO</div>
        <div>{{ formatMoney($salesSummary['total'], $business?->country) }}</div>
    @endif
</div>
@if ($receiptFormat === 'ticket' && isset($salesSummary['payment_breakdown']))
    @php
        $paymentLabels = [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'check' => 'Cheque',
            'credit_pending' => 'Crédito pendiente',
            'other' => 'Otros',
            'unclassified' => 'Sin clasificar',
        ];

        $salesMethodRows = collect($paymentLabels)->filter(
            fn (string $label, string $method) =>
                (float) ($salesSummary['payment_breakdown'][$method] ?? 0) > 0.005
        );
    @endphp

    @if ($salesMethodRows->isNotEmpty())
        <div class="line"></div>
        <h2 style="font-size:13px;">VENTAS POR MÉTODO DE PAGO</h2>
        <table>
            <tbody>
                @foreach ($salesMethodRows as $method => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="right">{{ formatMoney($salesSummary['payment_breakdown'][$method], $business?->country) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif
        <table>
            <tbody>
                <tr><td>Ventas brutas</td><td class="right">{{ formatMoney($salesSummary['gross_total'] ?? $salesSummary['total'], $business?->country) }}</td></tr>
                <tr><td>Anulaciones del turno</td><td class="right negative">-{{ formatMoney($salesSummary['same_period_cancellations'] ?? 0, $business?->country) }}</td></tr>
                <tr><td>Ventas a crédito</td><td class="right">{{ formatMoney($salesSummary['credit_sales_total'] ?? 0, $business?->country) }}</td></tr>
            </tbody>
        </table>
        @if ($salesSummary['ambiguous_count'] > 0)
            <p class="muted center">{{ $salesSummary['ambiguous_count'] }} venta(s) sin atribución segura no fueron incluidas.</p>
        @endif
        @if (($closingContract['source'] ?? null) === 'reconstructed')
            <p class="muted center">Reconstrucción histórica: este cierre no cuenta con fotografía inmutable.</p>
        @endif

        <div class="line"></div>

        <table>
            <tbody>
                <tr><td>Cierre</td><td class="right">#{{ $session->id }}</td></tr>
                <tr><td>Abierta</td><td class="right">{{ $sessionValue('opened_at', $session->opened_at)? \Carbon\Carbon::parse($sessionValue('opened_at', $session->opened_at))->timezone($timezone)->format('d/m/Y H:i') : '-' }}</td></tr>
                <tr><td>Cerrada</td><td class="right">{{ $sessionValue('closed_at', $session->closed_at)? \Carbon\Carbon::parse($sessionValue('closed_at', $session->closed_at))->timezone($timezone)->format('d/m/Y H:i') : '-' }}</td></tr>
                <tr><td>Abierta por</td><td class="right">{{ $sessionValue('opened_by', $session->openedBy?->name) ?? '-' }}</td></tr>
                <tr><td>Cerrada por</td><td class="right">{{ $sessionValue('closed_by', $session->closedBy?->name) ?? '-' }}</td></tr>
            </tbody>
        </table>

        <div class="line"></div>

        <h2 style="font-size: {{ $receiptFormat === 'ticket' ? '13px' : '16px' }};">Arqueo de efectivo</h2>
        @php
            $cashMethod = collect($paymentOperations['by_method'] ?? [])
                ->firstWhere('method', 'cash') ?? [];

            $cashReversalsCents = max(0, (int) round(100 * (
                (float) ($summary['cash_sale_cancellations'] ?? 0)
                + (float) ($summary['cash_refunds'] ?? 0)
            )));

            $sameTurnCents = min(
                $cashReversalsCents,
                max(0, (int) round(-100 * (float) ($cashMethod['same_turn_cancellations'] ?? 0)))
            );

            $priorCorrectionCents = min(
                $cashReversalsCents - $sameTurnCents,
                max(0, (int) round(-100 * (float) ($cashMethod['prior_period_corrections'] ?? 0)))
            );

            $unclassifiedCents = $cashReversalsCents - $sameTurnCents - $priorCorrectionCents;

            $netCashCurrentTurn = round(
                (float) $summary['cash_sales'] - $sameTurnCents / 100,
                2
            );
        @endphp
        <table>
            <tbody>
                <tr><td>Monto inicial</td><td class="right">{{ formatMoney($sessionValue('opening_amount', $session->opening_amount), $business?->country) }}</td></tr>
                <tr><td>Efectivo neto de ventas del turno</td><td class="right positive">{{ formatMoney($netCashCurrentTurn, $business?->country) }}</td></tr>
                @if ($priorCorrectionCents > 0)
                    <tr><td>Correcciones de cierres anteriores</td><td class="right negative">-{{ formatMoney($priorCorrectionCents / 100, $business?->country) }}</td></tr>
                @endif
                @if ($unclassifiedCents > 0)
                    <tr><td>Anulaciones/reembolsos sin origen seguro</td><td class="right negative">-{{ formatMoney($unclassifiedCents / 100, $business?->country) }}</td></tr>
                @endif
                <tr><td>Abonos netos en efectivo</td><td class="right">{{ formatMoney($summary['credit_payments'] - $summary['credit_payment_cancellations'], $business?->country) }}</td></tr>
                <tr><td>Gastos netos</td><td class="right negative">-{{ formatMoney($summary['expenses'] - $summary['expense_cancellations'], $business?->country) }}</td></tr>
                <tr><td>Compras pagadas de caja</td><td class="right negative">-{{ formatMoney($summary['cash_purchases'], $business?->country) }}</td></tr>
                <tr><td class="total">Efectivo esperado</td><td class="right total">{{ formatMoney($sessionValue('expected_cash', $session->expected_cash), $business?->country) }}</td></tr>
                <tr><td class="total">Efectivo contado</td><td class="right total">{{ formatMoney($sessionValue('counted_cash', $session->counted_cash), $business?->country) }}</td></tr>
                <tr>
                    <td class="total">Diferencia</td>
                    <td class="right total {{ (float) $sessionValue('difference', $session->difference) < 0 ? 'negative' : 'positive' }}">
                        {{ formatMoney($sessionValue('difference', $session->difference), $business?->country) }}
                    </td>
                </tr>
            </tbody>
        </table>

        @if ($sessionValue('notes', $session->notes) || $sessionValue('closing_notes', $session->closing_notes))
            <div class="line"></div>
            @if ($sessionValue('notes', $session->notes))
                <p><strong>Notas:</strong><br>{{ $sessionValue('notes', $session->notes) }}</p>
            @endif
            @if ($sessionValue('closing_notes', $session->closing_notes))
                <p><strong>Nota de cierre:</strong><br>{{ $sessionValue('closing_notes', $session->closing_notes) }}</p>
            @endif
        @endif

        <div class="line"></div>
        <h2 style="font-size: {{ $receiptFormat === 'ticket' ? '13px' : '16px' }};">Resumen por medio de pago</h2>
        @if ($receiptFormat === 'ticket')
            @php
                $ticketPaymentMethods = collect($paymentOperations['by_method'])->filter(
                    fn (array $method) => collect(['sales', 'same_turn_cancellations', 'prior_period_corrections', 'historical_unattributed', 'credit_payments', 'purchases', 'purchases_outside_cash', 'expenses', 'net'])
                        ->contains(fn (string $key) => (float) $method[$key] !== 0.0)
                );
            @endphp
            @forelse ($ticketPaymentMethods as $method)
                <div style="padding:5px 0; border-bottom:1px solid #e2e8f0;">
                    <div style="display:flex; justify-content:space-between; gap:8px;">
                        <strong>{{ match($method['method']) { 'cash' => 'EFECTIVO', 'card' => 'TARJETA', 'check' => 'CHEQUE', 'transfer' => 'TRANSFERENCIA', default => 'OTROS' } }}</strong>
                        <strong class="{{ $method['net'] < 0 ? 'negative' : 'positive' }}">{{ formatMoney($method['net'], $business?->country) }}</strong>
                    </div>
                    <div class="muted">
                        Ventas cobradas {{ formatMoney($method['sales'], $business?->country) }} · Anulaciones del turno {{ formatMoney($method['same_turn_cancellations'] ?? 0, $business?->country) }} · Correcciones anteriores {{ formatMoney($method['prior_period_corrections'] ?? 0, $business?->country) }}@if ((float) ($method['historical_unattributed'] ?? 0) !== 0.0) · Origen indeterminado {{ formatMoney($method['historical_unattributed'], $business?->country) }}@endif · Abonos {{ formatMoney($method['credit_payments'], $business?->country) }}
                        @if ((float) $method['purchases'] !== 0.0)
                            · Compras -{{ formatMoney(abs((float) $method['purchases']), $business?->country) }}
                        @endif
                        @if ((float) $method['purchases_outside_cash'] !== 0.0)
                            · Compras fuera {{ formatMoney($method['purchases_outside_cash'], $business?->country) }}
                        @endif
                        @if ((float) $method['expenses'] !== 0.0)
                            · Gastos -{{ formatMoney(abs((float) $method['expenses']), $business?->country) }}
                        @endif
                    </div>
                </div>
            @empty
                <p class="muted">No hay operaciones atribuibles a esta sesión.</p>
            @endforelse
        @else
            <table>
            <thead>
                <tr><th>Medio</th><th class="right">Ventas cobradas</th><th class="right">Anulaciones turno</th><th class="right">Correcciones anteriores</th><th class="right">Origen indeterminado</th><th class="right">Neto financiero</th><th class="right">Abonos netos</th><th class="right">Compras caja</th><th class="right">Compras fuera de caja</th><th class="right">Gastos netos</th></tr>
            </thead>
            <tbody>
                @foreach ($paymentOperations['by_method'] as $method)
                    <tr>
                        <td>{{ match($method['method']) { 'cash' => 'Efectivo', 'card' => 'Tarjeta', 'check' => 'Cheque', 'transfer' => 'Transferencia', default => 'Otros' } }}</td>
                        <td class="right">{{ formatMoney($method['sales'], $business?->country) }}</td>
                        <td class="right">{{ formatMoney($method['same_turn_cancellations'] ?? 0, $business?->country) }}</td>
                        <td class="right">{{ formatMoney($method['prior_period_corrections'] ?? 0, $business?->country) }}</td>
                        <td class="right">{{ formatMoney($method['historical_unattributed'] ?? 0, $business?->country) }}</td>
                        <td class="right">{{ formatMoney($method['net'], $business?->country) }}</td>
                        <td class="right">{{ formatMoney($method['credit_payments'], $business?->country) }}</td>
                        <td class="right negative">{{ $method['method'] === 'cash' ? formatMoney($method['purchases'], $business?->country) : '—' }}</td>
                        <td class="right">{{ formatMoney($method['purchases_outside_cash'], $business?->country) }}</td>
                        <td class="right negative">{{ $method['method'] === 'cash' ? formatMoney($method['expenses'], $business?->country) : '—' }}</td>

                    </tr>
                @endforeach
            </tbody>
            </table>
        @endif
        <p class="muted">Resumen informativo; el arqueo de efectivo se calcula exclusivamente con movimientos de caja.</p>
        @if (collect($paymentOperations['by_method'])->contains(fn (array $method) => (float) ($method['historical_unattributed'] ?? 0) !== 0.0))
            <p class="muted">Advertencia: hay anulaciones o reembolsos con origen histórico no determinado; no se asignaron a un turno arbitrariamente.</p>
        @endif

        @if (count($closingContract['corrections'] ?? []))
            <div class="line"></div>
            <h2 style="font-size: {{ $receiptFormat === 'ticket' ? '13px' : '16px' }};">Correcciones de períodos anteriores</h2>
            @foreach ($closingContract['corrections'] as $correction)
                <div style="padding: 5px 0; border-bottom: 1px solid #e2e8f0;">
                    <strong>{{ $correction['type'] === 'sale_cancellation' ? 'Anulación comercial/fiscal' : 'Corrección' }}</strong>
                    <span class="right" style="float:right">{{ formatMoney($correction['amount'], $business?->country) }}</span>
                    <div class="muted">Venta #{{ $correction['sale_number'] ?? '-' }} · Cierre original #{{ $correction['original_session_id'] ?? '-' }} · {{ $correction['occurred_at'] ? \Carbon\Carbon::parse($correction['occurred_at'])->timezone($timezone)->format('d/m/Y H:i') : '-' }}</div>
                    @if (! empty($correction['reason']))<div>{{ $correction['reason'] }}</div>@endif
                </div>
            @endforeach
        @endif

        @if ($receiptFormat !== 'ticket')
            <div class="line"></div>
            <h2 style="font-size: 16px;">Resumen de movimientos</h2>
            <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Descripción</th>
                    <th class="right">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movements as $movement)
                    <tr>
                        <td>{{ match($movement['type']) {
                            'opening' => 'Apertura',
                            'sale_cash' => 'Venta',
                            'sale_refund_cash' => 'Reembolso de venta',
                            'sale_cash_cancel' => 'Anulación',
                            'expense' => 'Gasto',
                            'expense_cancel' => 'Anulación de gasto',
                            'purchase_cash' => 'Compra',
                            default => 'Movimiento',
                        } }}</td>
                        <td>{{ $movement['description'] }}</td>
                        <td class="right {{ (float) $movement['amount'] < 0 ? 'negative' : 'positive' }}">
                            {{ formatMoney($movement['amount'], $business?->country) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        @endif

        @if ($receiptFormat !== 'ticket')
            <div class="line"></div>
            <h2 style="font-size: 16px;">Detalle de cobros y pagos</h2>
            @forelse ($paymentOperations['details'] as $operation)
                @php
                    $operationAt = $operation['occurred_at'] ? \Carbon\Carbon::parse($operation['occurred_at']) : null;
                @endphp
                <div style="padding: 5px 0; border-bottom: 1px solid #e2e8f0;">
                    <div style="display:flex; justify-content:space-between; gap:8px;"><strong>{{ $operation['label'] }}</strong><strong class="{{ $operation['signed_amount'] < 0 ? 'negative' : 'positive' }}">{{ formatMoney($operation['signed_amount'], $business?->country) }}</strong></div>
                    <div class="muted">{{ $operationAt?->timezone($timezone)->format('d/m/Y H:i') }} · {{ match($operation['method']) { 'cash' => 'Efectivo', 'card' => 'Tarjeta', 'check' => 'Cheque', 'transfer', 'bank_transfer' => 'Transferencia', default => 'Otro' } }} · {{ $operation['attribution'] === 'direct' ? 'Directo' : 'Reconstruido' }}</div>
                    @if ($operation['reference'] || $operation['party'])<div style="overflow-wrap:anywhere;">{{ $operation['reference'] }}{{ $operation['party'] ? ' · '.$operation['party'] : '' }}</div>@endif
                </div>
            @empty
                <p class="muted">No hay operaciones atribuibles a esta sesión.</p>
            @endforelse
            @if ($paymentOperations['has_more_details'])<p class="muted">Se muestran los primeros 200 de {{ $paymentOperations['detail_total'] }} movimientos.</p>@endif
            @if (count($paymentOperations['incidents']))
                <div class="line"></div><p><strong>Movimientos sin asignar con certeza</strong></p>
                @foreach ($paymentOperations['incidents'] as $operation)<p class="muted" style="overflow-wrap:anywhere;">{{ $operation['reference'] }}: {{ $operation['incident'] }}</p>@endforeach
            @endif
        @endif
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
