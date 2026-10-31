@php
    $delivery = $delivery ?? [];
    $hasDeliveryData = collect([
        $delivery['address_reference'] ?? null,
        $delivery['phone'] ?? null,
    ])
        ->contains(fn ($value) => filled(trim((string) $value)));
@endphp

@if ($hasDeliveryData)
    @once
        <style>
            .delivery-details { display: none; }
            .delivery-details.is-visible { display: block; }
            .delivery-print-toggle { align-items: center; display: inline-flex; font-size: 13px; gap: 6px; margin: 0 4px; }
            @media print {
                .delivery-details { display: none !important; }
                .delivery-details.is-visible { display: block !important; }
            }
        </style>
    @endonce
    <label class="delivery-print-toggle no-print">
        <input type="checkbox" data-delivery-print-toggle>
        <span>Imprimir Datos de Entrega</span>
    </label>
    @once
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('[data-delivery-print-toggle]').forEach(function (toggle) {
                    toggle.addEventListener('change', function () {
                        document.querySelectorAll('[data-delivery-details]').forEach(function (details) {
                            details.classList.toggle('is-visible', toggle.checked);
                        });
                    });
                });
            });
        </script>
    @endonce
@endif
