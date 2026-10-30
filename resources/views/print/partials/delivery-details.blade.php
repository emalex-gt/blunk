@php
    $delivery = $delivery ?? [];
    $hasDeliveryData = collect($delivery)
        ->contains(fn ($value) => filled(trim((string) $value)));
@endphp

@if ($hasDeliveryData)
    <section class="delivery-details" data-delivery-details>
        <strong>DATOS DE ENTREGA</strong>
        @if (filled($delivery['address'] ?? null))
            <p><strong>Dirección:</strong> {{ $delivery['address'] }}</p>
        @endif
        @if (filled($delivery['address_reference'] ?? null))
            <p><strong>Referencia de dirección:</strong> {{ $delivery['address_reference'] }}</p>
        @endif
        @if (filled($delivery['phone'] ?? null))
            <p><strong>Teléfono:</strong> {{ $delivery['phone'] }}</p>
        @endif
    </section>
@endif
