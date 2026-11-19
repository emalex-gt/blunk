@php
    $product = $item->product ?? null;
    $showCode = (bool) ($settings?->show_product_code_on_receipts ?? false);
    $showBarcode = (bool) ($settings?->show_product_barcode_on_receipts ?? false);
    $code = trim((string) ($product?->code ?? ''));
    $barcode = trim((string) ($product?->barcode ?? ''));
@endphp

@if (($showCode && $code !== '') || ($showBarcode && $barcode !== ''))
    <div class="muted" style="font-size: .82em; margin-top: 2px;">
        @if ($showCode && $code !== '')Código: {{ $code }}@endif
        @if ($showCode && $code !== '' && $showBarcode && $barcode !== '') · @endif
        @if ($showBarcode && $barcode !== '')Barras: {{ $barcode }}@endif
    </div>
@endif
