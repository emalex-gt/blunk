<button
    type="button"
    data-download-pdf
    data-pdf-target="{{ $target }}"
    data-pdf-filename="{{ $filename }}"
    data-pdf-kind="{{ $kind }}"
    @if (! empty($paper)) data-pdf-paper="{{ $paper }}" @endif
    @if (! empty($widthMm)) data-pdf-width-mm="{{ $widthMm }}" @endif
    data-pdf-margin-mm="{{ $marginMm }}"
    @if (! empty($class)) class="{{ $class }}" @endif
>Descargar PDF</button>

@once
    @vite('resources/js/print-pdf.js')
@endonce
