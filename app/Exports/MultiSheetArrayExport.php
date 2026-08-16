<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MultiSheetArrayExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $sheets,
    ) {
    }

    public function sheets(): array
    {
        return collect($this->sheets)
            ->map(fn (array $rows, string $title) => new ArraySheetExport($title, $rows))
            ->values()
            ->all();
    }
}
